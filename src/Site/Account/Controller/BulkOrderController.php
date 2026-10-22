<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\B2b\BulkOrder\BulkOrderException;
use App\Core\B2b\BulkOrder\BulkOrderInput;
use App\Core\B2b\BulkOrder\BulkOrderLine;
use App\Core\B2b\BulkOrder\BulkOrderResolver;
use App\Core\B2b\BulkOrder\BulkOrderRow;
use App\Core\Cart\CartResolver;
use App\Core\Cart\Exception\CartCreationRace;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\Customer;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Швидке замовлення списком у кабінеті (E13 PR-7, консиліум 07.10 П6): лише покупець з групою цін (інакше підказка
 * подати заявку оптовика). Рядки «SKU кількість» з поля або CSV → таблиця «розпізнано / не знайдено / недоступно /
 * мінімальна партія» з цінами групи (той самий фасад Pricing, що в кошику) → «додати в кошик» лише розпізнані рядки
 * (перевіряються знову). Кошик — свій за customer_id (CartResolver), чужий не дістати. CSRF stateless `account`,
 * ліміт розборів з IP; ліміти розміру й рядків — у BulkOrderInput.
 */
final class BulkOrderController extends AbstractController
{
    public function __construct(
        private readonly BulkOrderResolver $resolver,
        private readonly CartResolver $carts,
        private readonly CustomerContext $customers,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.bulk_order_ip')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $group = $this->customers->group();
        if (null === $group) {
            return $this->render('site/account/bulk_order.html.twig', ['customer' => $customer, 'group' => null, 'rows' => null, 'text' => '', 'error' => null, 'total' => null]);
        }
        $rows = null;
        $error = null;
        $text = '';
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            if (!$this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
                $error = 'too_many';
                $status = Response::HTTP_TOO_MANY_REQUESTS;
            } else {
                $text = (string) $request->request->get('lines', '');
                $file = $request->files->get('csv');
                try {
                    $raw = $file instanceof UploadedFile ? $this->fileContents($file) : $text;
                    $rows = $this->resolver->resolve(BulkOrderInput::parse($raw), $group, $request->getLocale());
                } catch (BulkOrderException $e) {
                    $error = $e->reason;
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                }
            }
        }

        return $this->render('site/account/bulk_order.html.twig', [
            'customer' => $customer,
            'group' => $group,
            'rows' => $rows,
            'text' => $text,
            'error' => $error,
            'total' => null === $rows ? null : BulkOrderResolver::total($rows, $this->currency),
        ], new Response(status: $status));
    }

    /** Розпізнані рядки з таблиці (rows[n][sku], rows[n][quantity]) — у кошик; звірка з каталогом повторюється. */
    #[IsGranted(Customer::ROLE)]
    public function add(Request $request): Response
    {
        $this->assertCsrf($request);
        $group = $this->customers->group();
        if (null === $group) {
            return $this->redirectToRoute('site_account_bulk');
        }
        // той самий лімітер, що на розбір: add звіряє до 500 рядків із каталогом знову
        if (!$this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('site.account.bulk.error.too_many'));

            return $this->redirectToRoute('site_account_bulk');
        }
        $lines = [];
        foreach ($request->request->all('rows') as $i => $row) {
            if (!\is_array($row) || \count($lines) >= BulkOrderInput::MAX_LINES) {
                continue;
            }
            $lines[] = new BulkOrderLine((int) $i + 1, mb_substr(trim((string) ($row['sku'] ?? '')), 0, 64), (string) ($row['quantity'] ?? ''));
        }
        $added = 0;
        $skipped = 0;
        try {
            foreach ($this->resolver->resolve($lines, $group, $request->getLocale()) as $row) {
                if (BulkOrderRow::OK !== $row->status || null === $row->variant) {
                    ++$skipped;
                    continue;
                }
                try {
                    $this->carts->currentOrCreate()->addItem($row->variant, $row->quantity, $row->quote?->configuration?->snapshot);
                    ++$added;
                } catch (CatalogInvariantViolation|InvalidArgumentException) {
                    ++$skipped;
                }
            }
            if ($added > 0) {
                $this->em->flush();
            }
        } catch (CartCreationRace) {
            return $this->redirectToRoute('site_cart');
        }
        if ($added > 0) {
            $this->addFlash('success', $this->translator->trans('site.account.bulk.flash.added', ['count' => $added]));
        }
        if ($skipped > 0) {
            $this->addFlash('error', $this->translator->trans('site.account.bulk.flash.skipped', ['count' => $skipped]));
        }

        return $added > 0 ? $this->redirectToRoute('site_cart') : $this->redirectToRoute('site_account_bulk');
    }

    /** @throws BulkOrderException too_large | upload_failed — збій завантаження не підміняється текстом з поля */
    private function fileContents(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new BulkOrderException(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'upload_failed');
        }
        if ($file->getSize() > BulkOrderInput::MAX_BYTES) {
            throw new BulkOrderException('too_large');
        }
        $contents = file_get_contents($file->getPathname());

        return false === $contents ? '' : $contents;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
    }
}

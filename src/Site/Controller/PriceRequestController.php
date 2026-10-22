<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\OrderSubmitGuard;
use App\Core\Order\PriceRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * «Запитати ціну» з картки товару з ціною за запитом: ім'я, телефон, коментар → PriceRequestService (лід у CRM).
 * Ті самі CSRF і ліміти, що й у швидкого замовлення; результат — flash на картці (сторінки «дякуємо» нема: замовлення
 * ще не оформлене) і generate_lead у dataLayer.
 */
final class PriceRequestController extends AbstractController
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceRequestService $priceRequests,
        private readonly OrderSubmitGuard $submitGuard,
        private readonly DataLayerCollector $dataLayer,
        private readonly EcommerceEventBuilder $events,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    #[Route('/price-request', name: 'site_price_request', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('price_request', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $this->submitGuard->check($request, (string) $request->request->get('phone', ''));
        $variant = $this->em->find(ProductVariant::class, $request->request->getInt('variant')) ?? throw $this->createNotFoundException();
        $locale = (string) $request->request->get('locale', $this->defaultLocale);
        $locale = \in_array($locale, $this->locales, true) ? $locale : $this->defaultLocale;
        $back = (string) $request->headers->get('referer', '');
        if ('' === $back || !str_starts_with($back, $request->getSchemeAndHttpHost().'/')) {
            $back = $this->generateUrl('site_home', ['_locale' => $locale]);
        }

        try {
            $this->priceRequests->submit(
                $variant,
                (string) $request->request->get('name', ''),
                (string) $request->request->get('phone', ''),
                (string) $request->request->get('comment', ''),
                $locale,
                $request->getClientIp(),
            );
        } catch (OrderValidationException $e) {
            foreach ($e->errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirect($back);
        }
        $this->dataLayer->queue($this->events->generateLead(null, $variant->getCurrency(), 'price_request'));
        $this->addFlash('success', $this->translator->trans('site.price_request.sent', [], 'messages', $locale));

        return $this->redirect($back);
    }
}

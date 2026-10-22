<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\LeadRequest;
use App\Core\Order\LeadRequestService;
use App\Core\Order\OrderSubmitGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Загальна заявка без товару (site/partials/_lead_form.html.twig): контакти, компанія, коментар, необов'язкове вкладення →
 * LeadRequestService (Order source=lead, лід у CRM і лист магазину). Захист як у запиту ціни: stateless CSRF, ліміти з IP і на
 * телефон (OrderSubmitGuard), плюс honeypot «website» — заповнений ботом запит мовчки «приймається» без запису.
 * Розмір тіла ріже nginx (location = /request, 11m) і AttachmentPolicy (10 МБ). Результат — flash на сторінці, з якої прийшли.
 */
final class LeadRequestController extends AbstractController
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly LeadRequestService $leads,
        private readonly OrderSubmitGuard $submitGuard,
        private readonly DataLayerCollector $dataLayer,
        private readonly EcommerceEventBuilder $events,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
    ) {
    }

    #[Route('/request', name: 'site_lead_request', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lead_request', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $locale = (string) $request->request->get('locale', $this->defaultLocale);
        $locale = \in_array($locale, $this->locales, true) ? $locale : $this->defaultLocale;
        $sourceUrl = $this->sameHostUrl($request, (string) $request->request->get('source_url', ''));
        $back = $this->sameHostUrl($request, (string) $request->headers->get('referer', '')) ?? $sourceUrl ?? $this->generateUrl('site_home', ['_locale' => $locale]);

        // honeypot: поле приховане стилями, людина його не бачить; бот заповнює → удаємо успіх, нічого не пишемо
        if ('' !== (string) $request->request->get('website', '')) {
            $this->addFlash('success', $this->translator->trans('site.lead.sent', [], 'messages', $locale));

            return $this->redirect($back);
        }
        $this->submitGuard->check($request, (string) $request->request->get('phone', ''));

        $file = $request->files->get('attachment');
        try {
            $this->leads->submit(new LeadRequest(
                name: (string) $request->request->get('name', ''),
                phone: (string) $request->request->get('phone', ''),
                email: (string) $request->request->get('email', ''),
                company: (string) $request->request->get('company', ''),
                comment: (string) $request->request->get('comment', ''),
                context: (string) $request->request->get('context', ''),
                choice: (string) $request->request->get('choice', ''),
                sourceUrl: $sourceUrl ?? '',
                attachment: $file instanceof UploadedFile ? $file : null,
                locale: $locale,
                ip: $request->getClientIp(),
            ));
        } catch (OrderValidationException $e) {
            foreach ($e->errors as $key) {
                $this->addFlash('error', $this->translator->trans($key, [], 'messages', $locale));
            }

            return $this->redirect($back);
        }
        $this->dataLayer->queue($this->events->generateLead(null, $this->currency, 'lead'));
        $this->addFlash('success', $this->translator->trans('site.lead.sent', [], 'messages', $locale));

        return $this->redirect($back);
    }

    /** Лише адреси цього сайту (open redirect і чужі URL у коментарі менеджеру — ні). */
    private function sameHostUrl(Request $request, string $url): ?string
    {
        $url = trim($url);

        return '' !== $url && str_starts_with($url, $request->getSchemeAndHttpHost().'/') && !str_contains($url, "\n") ? $url : null;
    }
}

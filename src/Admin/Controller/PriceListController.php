<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Core\B2b\PriceList\PriceListCsv;
use App\Core\Customer\Entity\CustomerGroup;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Прайс будь-якої групи для менеджера (E13 PR-8): той самий файл, що бачить оптовик, мовою ?locale= з app.locales (типово
 * мова магазину). Окремий маршрут admin_* на admin_host (AdminHostGuard), бо CRUD груп — лише ROLE_ADMIN, а прайс потрібен
 * менеджеру; кнопка — на групі в адмінці. Ліміт price_list_customer з ключем admin:<логін>.
 */
#[Route(host: '%admin_host%')]
final class PriceListController extends AbstractController
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly PriceListCsv $csv,
        #[Autowire(service: 'limiter.price_list_customer')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/price-list/{id}', name: 'admin_price_list', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function download(Request $request, #[MapEntity] CustomerGroup $group): Response
    {
        // той самий ліміт, що в покупця, з ключем менеджера: подвійний клік на великому каталозі — не два повні проходи (рев'ю #63 L2)
        if (!$this->limiter->create('admin:'.$this->getUser()?->getUserIdentifier())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, $this->translator->trans('price_list.error.too_many', [], 'admin'));
        }
        $locale = (string) $request->query->get('locale', $this->defaultLocale);
        if (!\in_array($locale, $this->locales, true)) {
            $locale = $this->defaultLocale;
        }

        return $this->csv->response($group, $locale);
    }
}

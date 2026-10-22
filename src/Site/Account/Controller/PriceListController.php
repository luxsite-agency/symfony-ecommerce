<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\B2b\PriceList\PriceListCsv;
use App\Core\B2b\Repository\CompanyMemberRepository;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Завантажити прайс» у кабінеті оптовика (E13 PR-8): лише схвалений менеджером покупець (член компанії) з активною групою
 * цін, лише власна група. Гість — на вхід (access_control), роздрібний чи вимкнений акаунт — 403. Файл потоковий, no-store,
 * без аналітики (маршрут site_account_*). Ліміт завантажень на покупця: прайс на весь каталог — дорогий запит.
 */
final class PriceListController extends AbstractController
{
    public function __construct(
        private readonly CompanyMemberRepository $members,
        private readonly PriceListCsv $csv,
        private readonly AuthAudit $audit,
        #[Autowire(service: 'limiter.price_list_customer')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function download(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $group = $customer->getCustomerGroup();
        if (!$customer->isActive() || null === $group || null === $this->members->findByCustomer($customer)) {
            throw $this->createAccessDeniedException('Прайс-лист доступний лише оптовим клієнтам');
        }
        if (!$this->limiter->create((string) $customer->getId())->consume()->isAccepted()) {
            $this->audit->log('price_list_rate_limited', $customer->getEmail(), $customer->getId());

            return $this->render('site/account/price_list_limited.html.twig', [], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }
        $this->audit->log('price_list_downloaded', $customer->getEmail(), $customer->getId(), ['group' => $group->getCode()]);

        return $this->csv->response($group, $request->getLocale());
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\B2b\Repository\CompanyMemberRepository;
use App\Core\B2b\Repository\WholesaleApplicationRepository;
use App\Core\B2b\WholesaleApplicationException;
use App\Core\B2b\WholesaleApplications;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Site\Account\Form\WholesaleApplicationData;
use App\Site\Account\Form\WholesaleApplicationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Стати оптовим клієнтом» у кабінеті (E13.1, PR-5): лише залогінений покупець з підтвердженим email (CustomerChecker
 * не впускає неперевірених, але loginUser у тестах і майбутні сценарії обходять checker, тож перевірка і тут).
 * Сторінка показує стан: компанія й група (схвалено), «чекає розгляду», причину відхилення з новою формою, або форму.
 * CSRF stateless (submit), ліміт подань з IP; у журналі лише id, без реквізитів.
 */
final class WholesaleController extends AbstractController
{
    public function __construct(
        private readonly WholesaleApplications $applications,
        private readonly WholesaleApplicationRepository $repository,
        private readonly CompanyMemberRepository $members,
        private readonly AuthAudit $audit,
        #[Autowire(service: 'limiter.wholesale_apply_ip')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function apply(Request $request, #[CurrentUser] Customer $customer): Response
    {
        if (!$customer->isEmailVerified()) {
            throw $this->createAccessDeniedException('Email не підтверджено');
        }
        $member = $this->members->findByCustomer($customer);
        $latest = $this->repository->findLatest($customer);
        $canApply = $this->applications->canApply($customer);
        $form = null;
        $limited = false;
        if ($canApply) {
            $form = $this->createForm(WholesaleApplicationType::class, $data = new WholesaleApplicationData());
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
                    $this->audit->log('wholesale_rate_limited', $customer->getEmail(), $customer->getId());
                    $limited = true;
                } else {
                    try {
                        $this->applications->submit($customer, $data->companyName, $data->taxId, $data->vatPayer, $data->contactName, $data->contactPhone, $data->note);
                        $this->addFlash('success', 'site.account.wholesale.flash.submitted');

                        return $this->redirectToRoute('site_account_wholesale');
                    } catch (WholesaleApplicationException $e) {
                        $this->addFlash('error', $e->getMessage());

                        return $this->redirectToRoute('site_account_wholesale');
                    }
                }
            }
        }

        return $this->render('site/account/wholesale.html.twig', [
            'customer' => $customer,
            'member' => $member,
            'latest' => $latest,
            'form' => $form,
            'limited' => $limited,
        ], new Response(status: $limited ? Response::HTTP_TOO_MANY_REQUESTS : (null !== $form && $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }
}

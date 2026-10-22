<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Repository\CustomerRepository;
use App\Core\Demo\DemoAccounts;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\EmailRequestType;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Вхід покупця (firewall site): форма email+пароль (POST перехоплює form_login), вихід (POST з CSRF — перехоплює
 * logout), вхід одноразовим посиланням (login_link: check_post_only — GET лише показує кнопку, бо сканери пошти
 * відкривають посилання GET-ом і з'їли б його). Маршрути — SiteRouteLoader (site_account_*).
 */
final class LoginController extends AbstractController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly AccountMails $mails,
        private readonly AuthAudit $audit,
        private readonly DemoAccounts $demo,
        #[Autowire('%app.customer_login_link_ttl%')] private readonly int $loginLinkTtl,
    ) {
    }

    public function login(Request $request, AuthenticationUtils $authUtils): Response
    {
        if ($request->hasPreviousSession() && null !== $this->getUser()) {
            return $this->redirectToRoute('site_account');
        }
        // помилку й останній email кладе в сесію form_login; без cookie сесії її нема — і не стартуємо нову
        $error = $request->hasPreviousSession() ? $authUtils->getLastAuthenticationError() : null;

        return $this->render('site/account/login.html.twig', [
            'last_email' => $request->hasPreviousSession() ? $authUtils->getLastUsername() : '',
            'error' => $error?->getMessageKey(),
            'error_data' => $error?->getMessageData() ?? [],
            // блок «Демо-доступ» лише в демо-магазині (app.demo.customer_password задано): логіни й пароль публічні
            'demo_logins' => $this->demo->isEnabled() ? $this->demo->logins() : [],
            'demo_password' => $this->demo->password(),
        ]);
    }

    public function logout(): never
    {
        throw new LogicException('Перехоплює firewall site (logout).');
    }

    public function loginLink(Request $request, LoginLinkHandlerInterface $loginLinks): Response
    {
        $form = $this->createForm(EmailRequestType::class, ['email' => '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();
            // ліміт споживається для будь-якої адреси — інакше його спрацювання видавало б наявні акаунти
            $allowed = $this->mails->allow($request, $email);
            $customer = $this->customers->findOneByEmail($email);
            // лише підтвердженому й активному зі способом входу (пароль або Google); іншим — нічого, але відповідь та сама
            if ($allowed && null !== $customer && $customer->isActive() && $customer->isEmailVerified() && $customer->hasLoginMethod()) {
                $this->mails->loginLink($customer, $loginLinks->createLoginLink($customer, $request)->getUrl(), $this->loginLinkTtl);
            } elseif (null === $customer) {
                $this->audit->log('login_link_unknown', $email);
            }
            $this->addFlash('success', 'site.account.flash.check_email');

            return $this->redirectToRoute('site_account_login');
        }

        return $this->render('site/account/login_link.html.twig', ['form' => $form]);
    }

    /** GET з листа — сторінка з кнопкою; POST на ту саму адресу обробляє login_link. */
    public function loginLinkCheck(Request $request): Response
    {
        return $this->render('site/account/login_link_check.html.twig', ['action' => $request->getUri()]);
    }
}

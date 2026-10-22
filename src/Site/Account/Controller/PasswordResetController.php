<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Customer\Repository\CustomerRepository;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\EmailRequestType;
use App\Site\Account\Form\NewPasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Відновлення пароля одноразовим посиланням (lifetime app.customer_reset_ttl). Запит — однакова відповідь для будь-якої
 * адреси. Новий пароль змінює хеш і authVersion: інші сесії, remember_me і старі листи гаснуть; на пошту — сповіщення.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $em,
        private readonly AuthTokens $tokens,
        private readonly AccountMails $mails,
        private readonly AuthAudit $audit,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function request(Request $request): Response
    {
        $form = $this->createForm(EmailRequestType::class, ['email' => '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();
            // ліміт споживається для будь-якої адреси — інакше його спрацювання видавало б наявні акаунти
            $allowed = $this->mails->allow($request, $email);
            $customer = $this->customers->findOneByEmail($email);
            if (null !== $customer && $customer->isActive() && $customer->isEmailVerified()) {
                if ($allowed) {
                    $token = $this->tokens->issue($customer, AuthTokenPurpose::ResetPassword);
                    $this->em->flush();
                    $this->mails->reset($request, $customer, $token);
                }
            } else {
                $this->audit->log('reset_unknown', $email, $customer?->getId());
            }
            $this->addFlash('success', 'site.account.flash.check_email');

            return $this->redirectToRoute('site_account_login');
        }

        return $this->render('site/account/password_request.html.twig', ['form' => $form]);
    }

    public function reset(Request $request, string $token, Security $security): Response
    {
        if (null === $this->tokens->find($token, AuthTokenPurpose::ResetPassword)) {
            return $this->render('site/account/password_reset.html.twig', ['form' => null], new Response(status: 410));
        }
        $form = $this->createForm(NewPasswordType::class, ['password' => '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $password */
            $password = $form->get('password')->getData();
            $customer = $this->tokens->consume($token, AuthTokenPurpose::ResetPassword, function (Customer $customer) use ($password): void {
                $customer->changePasswordHash($this->hasher->hashPassword($customer, $password));
            });
            // Security::login() викликає лише checkPreAuth (порожній): вимкненого не впускати (повторне рев'ю #45, п. D)
            if (null === $customer || !$customer->isActive()) {
                return $this->render('site/account/password_reset.html.twig', ['form' => null], new Response(status: 410));
            }
            $this->audit->log('password_reset', $customer->getEmail(), $customer->getId());
            $this->mails->passwordChanged($request, $customer);
            $security->login($customer, 'form_login', 'site');
            $this->addFlash('success', 'site.account.flash.password_changed');

            return $this->redirectToRoute('site_account');
        }

        return $this->render('site/account/password_reset.html.twig', ['form' => $form]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Auth\EmailOwnership;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\EmailChangeType;
use App\Site\Account\Form\PasswordChangeType;
use App\Site\Account\Form\ProfileData;
use App\Site\Account\Form\ProfileType;
use App\Site\Account\Reauth;
use App\Site\Account\ReauthResult;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Профіль покупця (E12 PR-6): ім'я, телефон E.164, мова, згода на маркетинг (журнал згод). Пароль і email — окремо,
 * з повторною автентифікацією поточним паролем (консиліум П5): новий пароль гасить інші сесії й remember_me через
 * authVersion (поточна сесія перелогінюється); нова адреса діє лише після підтвердження листом на неї, на стару
 * іде сповіщення. Усі POST — stateless CSRF (token_id submit).
 */
final class ProfileController extends AbstractController
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Consents $consents,
        private readonly AuthAudit $audit,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuthTokens $tokens,
        private readonly AccountMails $mails,
        private readonly EmailOwnership $emails,
        private readonly Reauth $reauth,
        private readonly Security $security,
        #[Autowire('%app.locales%')] private readonly array $locales,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function edit(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $data = ProfileData::of($customer, $this->consents->isGranted($customer, ConsentPurpose::MarketingEmail));
        $form = $this->createForm(ProfileType::class, $data, ['locales' => $this->locales]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $phoneChanged = $customer->getPhone() !== $data->phone;
                $customer->rename($data->name);
                $customer->setPhone($data->phone);
                if (\in_array($data->locale, $this->locales, true)) {
                    $customer->setLocale($data->locale);
                }
                $consentChanged = $this->consents->record($customer, ConsentPurpose::MarketingEmail, $data->marketing, 'profile', $request->getLocale());
                $this->em->flush();
                $this->audit->log('profile_updated', $customer->getEmail(), $customer->getId(), ['phone_changed' => $phoneChanged, 'marketing' => $consentChanged ? $data->marketing : null]);
                $this->addFlash('success', 'site.account.profile.flash.saved');

                return $this->redirectToRoute('site_account_profile');
            } catch (InvalidArgumentException $e) {
                $form->get('phone')->addError(new FormError('site.account.error.phone_invalid'));
            }
        }

        return $this->render('site/account/profile.html.twig', ['customer' => $customer, 'form' => $form, 'locales' => $this->locales], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[IsGranted(Customer::ROLE)]
    public function password(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $form = $this->createForm(PasswordChangeType::class, ['current' => '', 'password' => '']);
        $form->handleRequest($request);
        $reauth = null;
        if ($form->isSubmitted() && $form->isValid() && ReauthResult::Ok === ($reauth = $this->reauth->check($form, $customer))) {
            /** @var string $password */
            $password = $form->get('password')->getData();
            $customer->changePasswordHash($this->hasher->hashPassword($customer, $password));
            $this->em->flush();
            $this->audit->log('password_changed', $customer->getEmail(), $customer->getId());
            $this->mails->passwordChanged($request, $customer);
            // authVersion змінився: інші пристрої вийшли; ця сесія отримує свіжий токен, інакше вийшла б і вона
            $this->security->login($customer, 'form_login', 'site');
            $this->addFlash('success', 'site.account.flash.password_changed');

            return $this->redirectToRoute('site_account_profile');
        }

        return $this->render('site/account/password_change.html.twig', ['form' => $form], new Response(status: Reauth::status($form, $reauth)));
    }

    /** Запит зміни email: пароль, нова адреса → лист з посиланням на НОВУ адресу. Відповідь однакова, зайнята адреса чи ні. */
    #[IsGranted(Customer::ROLE)]
    public function email(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $form = $this->createForm(EmailChangeType::class, ['email' => '', 'current' => '']);
        $form->handleRequest($request);
        $reauth = null;
        if ($form->isSubmitted() && $form->isValid() && ReauthResult::Ok === ($reauth = $this->reauth->check($form, $customer))) {
            $email = Customer::normalizeEmail((string) $form->get('email')->getData());
            if ($email === $customer->getEmail()) {
                $form->get('email')->addError(new FormError('site.account.profile.error.email_same'));
            } else {
                $allowed = $this->mails->allow($request, $email);
                $taken = $this->emails->isOwned($email); // неперевірена заготовка адресу не тримає (рев'ю #60 v2, L3)
                if ($taken) {
                    $this->audit->log('email_change_taken', $email, $customer->getId());
                } elseif ($allowed) {
                    $token = $this->tokens->issue($customer, AuthTokenPurpose::ChangeEmail, $email);
                    $this->em->flush();
                    $this->mails->emailChange($request, $customer, $email, $token);
                    $this->audit->log('email_change_requested', $customer->getEmail(), $customer->getId());
                }
                $this->addFlash('success', 'site.account.profile.flash.email_sent');

                return $this->redirectToRoute('site_account_profile');
            }
        }

        return $this->render('site/account/email_change.html.twig', ['form' => $form, 'customer' => $customer], new Response(status: Reauth::status($form, $reauth)));
    }

    /** GET — кнопка (сканери пошти не витрачають токен); POST — нова адреса діє, стара отримує сповіщення, сесія оновлюється. */
    #[IsGranted(Customer::ROLE)]
    public function emailConfirm(Request $request, string $token, #[CurrentUser] Customer $customer): Response
    {
        $found = $this->tokens->find($token, AuthTokenPurpose::ChangeEmail);
        if (null === $found || $found->getCustomer()->getId() !== $customer->getId() || null === $found->getTargetEmail()) {
            return $this->render('site/account/email_confirm.html.twig', ['ok' => false, 'email' => null], new Response(status: 410));
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Недійсний CSRF-токен');
            }
            $old = $customer->getEmail();
            $new = $found->getTargetEmail();
            try {
                $released = null;
                $changed = $this->tokens->consume($token, AuthTokenPurpose::ChangeEmail, function (Customer $c) use ($new, &$released): void {
                    $released = $this->emails->releasePlaceholder($new); // заготовку, що застовпила адресу, прибираємо в тій самій транзакції
                    $c->changeEmail($new);
                });
            } catch (UniqueConstraintViolationException) {
                $changed = null; // адресу зайняли, поки лист чекав
            }
            if (null === $changed) {
                $this->addFlash('error', 'site.account.profile.error.email_taken');

                return $this->redirectToRoute('site_account_profile');
            }
            $revert = $this->tokens->issue($changed, AuthTokenPurpose::RevertEmail, $old);
            $this->em->flush();
            $this->audit->log('email_changed', $new, $customer->getId(), ['placeholder_removed' => $released]);
            $this->mails->emailChanged($request, $changed, $old, $revert, $this->tokens->ttl(AuthTokenPurpose::RevertEmail));
            $this->security->login($changed, 'form_login', 'site'); // у сесії стара адреса — isEqualTo вийшов би
            $this->addFlash('success', 'site.account.profile.flash.email_changed');

            return $this->redirectToRoute('site_account_profile');
        }

        return $this->render('site/account/email_confirm.html.twig', ['ok' => true, 'email' => $found->getTargetEmail()]);
    }

    /**
     * З листа на СТАРУ адресу (публічно: власник скриньки вже без сесії, бо зловмисник змінив адресу): GET — кнопка,
     * POST — адреса повертається, пароль стирається, authVersion++ (усі сесії й remember_me гаснуть); далі «забули пароль»
     * на стару адресу. Неперевірену заготовку, якою зловмисник «застовпив» стару адресу, прибираємо в тій самій транзакції
     * (рев'ю #60 v2, M1); якщо стару адресу тим часом зайняв справжній акаунт — повернути не можна, лишається підтримка.
     */
    public function emailRevert(Request $request, string $token): Response
    {
        $found = $this->tokens->find($token, AuthTokenPurpose::RevertEmail);
        if (null === $found || null === $found->getTargetEmail()) {
            return $this->render('site/account/email_revert.html.twig', ['ok' => false, 'email' => null], new Response(status: 410));
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Недійсний CSRF-токен');
            }
            $old = $found->getTargetEmail();
            if ($this->emails->isOwned($old)) {
                $this->addFlash('error', 'site.account.profile.error.email_taken');

                return $this->redirectToRoute('site_account_login');
            }
            $released = null;
            try {
                $customer = $this->tokens->consume($token, AuthTokenPurpose::RevertEmail, function (Customer $c) use ($old, &$released): void {
                    $released = $this->emails->releasePlaceholder($old);
                    $c->changeEmail($old);
                    $c->lockOut();
                });
            } catch (UniqueConstraintViolationException) {
                $customer = null;
            }
            if (null === $customer) {
                return $this->render('site/account/email_revert.html.twig', ['ok' => false, 'email' => null], new Response(status: 410));
            }
            $this->audit->log('email_reverted', $old, $customer->getId(), ['placeholder_removed' => $released]);
            $this->addFlash('success', 'site.account.profile.flash.email_reverted');

            return $this->redirectToRoute('site_account_password');
        }

        return $this->render('site/account/email_revert.html.twig', ['ok' => true, 'email' => $found->getTargetEmail()]);
    }
}

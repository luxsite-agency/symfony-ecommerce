<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Repository\CustomerRepository;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\RegistrationData;
use App\Site\Account\Form\RegistrationType;
use App\Site\Account\Form\VerifyEmailType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Реєстрація з обов'язковим підтвердженням email (консиліум 07.10, П1, П5). Форма приймає лише адресу; відповідь
 * однакова для нової, неперевіреної й зайнятої адреси: «перевірте пошту». Нова чи неперевірена — лист підтвердження.
 * Імʼя й пароль задає власник скриньки на сторінці з листа (рев'ю безпеки #45, п. 2): пароль з форми реєстрації вже
 * не стає паролем акаунта, тож ні «попередня реєстрація» чужої адреси, ні повторна реєстрація поверх листа власника
 * не дають зловмиснику входу, хоч би який лист власник відкрив. Підтверджена — лист «у вас уже є акаунт» з посиланням
 * на новий пароль.
 */
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $em,
        private readonly AuthTokens $tokens,
        private readonly AccountMails $mails,
        private readonly AuthAudit $audit,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Consents $consents,
        #[Autowire(service: 'limiter.customer_register_ip')] private readonly RateLimiterFactoryInterface $registerLimiter,
    ) {
    }

    public function register(Request $request): Response
    {
        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->registerLimiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
                $this->audit->log('register_rate_limited', $data->email);

                return $this->render('site/account/register.html.twig', ['form' => $form, 'limited' => true], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }
            $allowed = $this->mails->allow($request, $data->email);
            $customer = $this->customers->findOneByEmail($data->email);
            $new = null === $customer;
            try {
                // без імені: його задасть власник скриньки на сторінці підтвердження
                $customer ??= new Customer($data->email, '', $request->getLocale());
            } catch (InvalidArgumentException) {
                // Assert\Email (html5) і FILTER_VALIDATE_EMAIL сутності розходяться на крайніх адресах
                $form->get('email')->addError(new FormError('site.account.error.email_invalid'));

                return $this->render('site/account/register.html.twig', ['form' => $form, 'limited' => false]);
            }
            if ($new) {
                $this->em->persist($customer);
            }
            // журнал згод (E12 PR-6): обробка ПД, джерело register, версія й хеш тексту мовою сторінки
            $this->consents->record($customer, ConsentPurpose::PdProcessing, true, 'register', $request->getLocale());
            // вимкненому — ні токена, ні листа, відповідь та сама (повторне рев'ю безпеки #45, п. D)
            if (!$customer->isActive()) {
                $this->audit->log('register_inactive', $data->email, $customer->getId());
                $this->addFlash('success', 'site.account.flash.check_email');

                return $this->redirectToRoute('site_account_login');
            }
            $purpose = $customer->isEmailVerified() ? AuthTokenPurpose::ResetPassword : AuthTokenPurpose::VerifyEmail;
            $token = $this->tokens->issue($customer, $purpose);
            $this->em->flush();
            if ($customer->isEmailVerified()) {
                if ($allowed) {
                    $this->mails->accountExists($request, $customer, $token);
                }
                $this->audit->log('register_existing', $data->email, $customer->getId());
            } else {
                if ($allowed) {
                    $this->mails->verify($request, $customer, $token);
                }
                $this->audit->log($new ? 'register' : 'register_repeat', $data->email, $customer->getId());
            }
            $this->addFlash('success', 'site.account.flash.check_email');

            return $this->redirectToRoute('site_account_login');
        }

        return $this->render('site/account/register.html.twig', ['form' => $form, 'limited' => false]);
    }

    /**
     * GET — форма «імʼя й пароль» (токен не витрачається: посилання з листа відкривають і сканери пошти). POST — пароль
     * і імʼя від власника скриньки, підтвердження email, вхід. Форма — stateless CSRF (token_id submit).
     */
    public function verify(Request $request, string $token, Security $security): Response
    {
        if (null === $this->tokens->find($token, AuthTokenPurpose::VerifyEmail)) {
            return $this->render('site/account/verify.html.twig', ['form' => null], new Response(status: 410));
        }
        $form = $this->createForm(VerifyEmailType::class, ['name' => '', 'password' => '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $name */
            $name = $form->get('name')->getData();
            /** @var string $password */
            $password = $form->get('password')->getData();
            $customer = $this->tokens->consume($token, AuthTokenPurpose::VerifyEmail, function (Customer $customer) use ($name, $password): void {
                $customer->verifyEmail($this->hasher->hashPassword($customer, $password), $name);
            });
            // Security::login() викликає лише checkPreAuth (порожній), тож активність — тут; токен вимкненого й так
            // недійсний (CustomerAuthToken::isUsable), це другий замок (повторне рев'ю безпеки #45, п. D)
            if (null === $customer || !$customer->isActive()) {
                return $this->render('site/account/verify.html.twig', ['form' => null], new Response(status: 410));
            }
            $this->audit->log('email_verified', $customer->getEmail(), $customer->getId());
            $security->login($customer, 'form_login', 'site');
            $this->addFlash('success', 'site.account.flash.verified');

            return $this->redirectToRoute('site_account');
        }

        return $this->render('site/account/verify.html.twig', ['form' => $form]);
    }
}

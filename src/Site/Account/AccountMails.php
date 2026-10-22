<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Message\SendCustomerAuthMail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Листи покупцю з посиланнями: URL — з хоста й мови поточного запиту (магазин на кількох доменах; чужий Host у прод
 * не пройде, framework.trusted_hosts з site_host/admin_host, Kernel::process), відправка — у транспорт auth_mail.
 * Ліміт ставиться на відправлення (консиліум 07.10, П5): з IP, на адресу за 15 хв і на адресу за добу (рев'ю безпеки
 * #45, п. 8: без добової межі ротація IP давала до 288 листів на скриньку); перевищення — лист не йде, відповідь
 * сторінки та сама (без розкриття, чи є акаунт). IP і 15-хвилинний ліміт списуються за кожен запит (навіть на чужу
 * адресу, щоб спрацювання не видавало акаунти), а добовий — лише за фактично надісланий лист (send()): інакше 10
 * відхилених запитів за хвилину блокували б жертві листи на добу (повторне рев'ю безпеки #45, п. B). Листи verify і account_exists — без імені: їх може замовити будь-хто
 * на чужу адресу, і ім'я з форми стало б каналом для фішингу від імені магазину (рев'ю безпеки #45, п. 7).
 */
final class AccountMails
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthAudit $audit,
        #[Autowire(service: 'limiter.auth_mail_ip')] private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.auth_mail_email')] private readonly RateLimiterFactoryInterface $emailLimiter,
        #[Autowire(service: 'limiter.auth_mail_email_daily')] private readonly RateLimiterFactoryInterface $emailDailyLimiter,
        #[Autowire('%app.customer_verify_ttl%')] private readonly int $verifyTtl,
        #[Autowire('%app.customer_reset_ttl%')] private readonly int $resetTtl,
    ) {
    }

    /**
     * Чи можна надіслати ще один лист з цього IP на цю адресу. Споживає ліміти IP і 15 хв; добовий лише перевіряє
     * (consume(0)), його списує send().
     */
    public function allow(Request $request, string $email): bool
    {
        $ip = $this->ipLimiter->create($request->getClientIp() ?? 'anon')->consume();
        $address = $this->emailLimiter->create(Customer::normalizeEmail($email))->consume();
        $daily = $this->emailDailyLimiter->create(Customer::normalizeEmail($email))->consume(0);
        if ($ip->isAccepted() && $address->isAccepted() && $daily->getRemainingTokens() > 0) {
            return true;
        }
        $this->audit->log('mail_rate_limited', $email);

        return false;
    }

    public function verify(Request $request, Customer $customer, string $token): void
    {
        $this->send(SendCustomerAuthMail::VERIFY, $customer, $this->url('site_account_verify', $request, ['token' => $token]), $this->verifyTtl, withName: false);
    }

    /** Реєстрація на вже підтверджену адресу: «у вас уже є акаунт» з посиланням на новий пароль. */
    public function accountExists(Request $request, Customer $customer, string $resetToken): void
    {
        $this->send(SendCustomerAuthMail::ACCOUNT_EXISTS, $customer, $this->url('site_account_password_reset', $request, ['token' => $resetToken]), $this->resetTtl, withName: false);
    }

    public function reset(Request $request, Customer $customer, string $token): void
    {
        $this->send(SendCustomerAuthMail::RESET, $customer, $this->url('site_account_password_reset', $request, ['token' => $token]), $this->resetTtl);
    }

    public function loginLink(Customer $customer, string $url, int $ttl): void
    {
        $this->send(SendCustomerAuthMail::LOGIN_LINK, $customer, $url, $ttl);
    }

    /**
     * Сповіщення після зміни пароля: «якщо це не ви — відновіть доступ». Якщо підключено Google — у листі його адреса:
     * після «забули пароль» сесії гаснуть, а прив'язаний Google лишається входом (рев'ю #74, M1).
     */
    public function passwordChanged(Request $request, Customer $customer): void
    {
        $this->send(SendCustomerAuthMail::PASSWORD_CHANGED, $customer, $this->url('site_account_password', $request), 0, detail: $customer->getGoogleEmail());
    }

    /** Google підключено (з кабінету або автоматично при вході за підтвердженою адресою): «якщо це були не ви — відключіть». */
    public function googleLinked(Request $request, Customer $customer): void
    {
        $this->send(SendCustomerAuthMail::GOOGLE_LINKED, $customer, $this->url('site_account_profile', $request), 0, detail: $customer->getGoogleEmail());
    }

    public function googleUnlinked(Request $request, Customer $customer, string $googleEmail): void
    {
        $this->send(SendCustomerAuthMail::GOOGLE_UNLINKED, $customer, $this->url('site_account_profile', $request), 0, detail: $googleEmail);
    }

    /** Зміна email: посилання підтвердження на нову адресу (токен ChangeEmail, 24 год); лист без імені не потрібен — запит робить сам покупець. */
    public function emailChange(Request $request, Customer $customer, string $newEmail, string $token): void
    {
        $this->emailDailyLimiter->create(Customer::normalizeEmail($newEmail))->consume();
        $this->bus->dispatch(new SendCustomerAuthMail(SendCustomerAuthMail::EMAIL_CHANGE, $newEmail, $customer->getName(), $customer->getLocale(), $this->url('site_account_email_confirm', $request, ['token' => $token]), intdiv($this->verifyTtl, 60)));
        $this->audit->log('mail_email_change', $newEmail, $customer->getId());
    }

    /** Сповіщення на СТАРУ адресу після зміни з посиланням «повернути стару адресу й заблокувати вхід» (токен RevertEmail, 7 днів). */
    public function emailChanged(Request $request, Customer $customer, string $oldEmail, string $revertToken, int $ttl): void
    {
        $this->bus->dispatch(new SendCustomerAuthMail(SendCustomerAuthMail::EMAIL_CHANGED, $oldEmail, $customer->getName(), $customer->getLocale(), $this->url('site_account_email_revert', $request, ['token' => $revertToken]), intdiv($ttl, 60)));
        $this->audit->log('mail_email_changed', $oldEmail, $customer->getId());
    }

    /** Підтвердження видалення — на адресу, яка щойно була в акаунті (сам акаунт уже стерто, тож адреса передається явно). */
    public function deleted(Request $request, string $email, string $name, string $locale): void
    {
        $this->bus->dispatch(new SendCustomerAuthMail(SendCustomerAuthMail::DELETED, $email, $name, $locale, $this->url('site_home', $request), 0));
        $this->audit->log('mail_deleted', $email);
    }

    /** Посилання на файл експорту (сам лист шле ExportCustomerDataHandler, коли файл готовий). */
    public function exportUrl(Request $request, string $token): string
    {
        return $this->url('site_account_export_download', $request, ['token' => $token]);
    }

    /** @param array<string, string> $params */
    private function url(string $route, Request $request, array $params = []): string
    {
        return $this->urls->generate($route, $params + ['_locale' => $request->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function send(string $kind, Customer $customer, string $url, int $ttlSeconds, bool $withName = true, ?string $detail = null): void
    {
        $this->emailDailyLimiter->create(Customer::normalizeEmail($customer->getEmail()))->consume();
        $this->bus->dispatch(new SendCustomerAuthMail($kind, $customer->getEmail(), $withName ? $customer->getName() : '', $customer->getLocale(), $url, intdiv($ttlSeconds, 60), $detail));
        $this->audit->log('mail_'.$kind, $customer->getEmail(), $customer->getId());
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Message;

use App\Core\Shared\Messenger\AuthMailMessage;

/**
 * Лист покупцю з посиланням (транспорт auth_mail): kind — шаблон emails/account/<kind>.*.twig. URL будує Site-шар
 * з хоста запиту (магазин на кількох доменах), тож воркер нічого не генерує й не знає токена окремо від посилання.
 */
final readonly class SendCustomerAuthMail implements AuthMailMessage
{
    public const string VERIFY = 'verify';
    public const string ACCOUNT_EXISTS = 'account_exists';
    public const string RESET = 'reset';
    public const string LOGIN_LINK = 'login_link';
    public const string PASSWORD_CHANGED = 'password_changed';
    /** E12 PR-6: лист на НОВУ адресу з посиланням підтвердження; сповіщення на стару; експорт готовий; акаунт видалено */
    public const string EMAIL_CHANGE = 'email_change';
    public const string EMAIL_CHANGED = 'email_changed';
    public const string EXPORT = 'export';
    public const string DELETED = 'deleted';
    /** Google підключено / відключено (рев'ю #74, M1): detail — адреса акаунта Google, щоб покупець бачив, ЯКИЙ підключили */
    public const string GOOGLE_LINKED = 'google_linked';
    public const string GOOGLE_UNLINKED = 'google_unlinked';

    public function __construct(
        public string $kind,
        public string $email,
        public string $name,
        public string $locale,
        public string $url,
        public int $ttlMinutes = 0,
        public ?string $detail = null,
    ) {
    }
}

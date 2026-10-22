<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Message;

use App\Core\Shared\Messenger\AuthMailMessage;

/**
 * Лист покупцю про рішення по заявці оптовика (E13): транспорт auth_mail (AuthMailMessage), щоб не чекати за
 * імпортом у async. Шаблон emails/account/wholesale_<kind>.*.twig; reason — причина відхилення (порожньо при схваленні).
 * Реквізитів компанії в повідомленні нема: у черзі лише те, що потрібно листу.
 */
final readonly class SendWholesaleDecisionMail implements AuthMailMessage
{
    public const string APPROVED = 'wholesale_approved';
    public const string REJECTED = 'wholesale_rejected';

    public function __construct(
        public string $kind,
        public string $email,
        public string $name,
        public string $locale,
        public string $url,
        public string $reason = '',
    ) {
    }
}

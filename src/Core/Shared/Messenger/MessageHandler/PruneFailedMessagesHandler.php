<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Messenger\MessageHandler;

use App\Core\Shared\Messenger\Message\PruneFailedMessages;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Черга failed тримає тіла повідомлень безстроково, а SendCustomerAuthMail несе готові посилання з токенами
 * (консиліум безпеки 10.10.2026, п. 10; SendWholesaleDecisionMail токенів не має — там URL кабінету): після строку
 * посилання повторювати їх нема сенсу, а зберігати — ризик. Видаляються ЛИШЕ такі повідомлення, старші за
 * app.messenger_failed_days (7). Решта failed (PushOrderToCrm, листи замовлень) лишається безстроково: їх треба
 * повторити вручну (messenger:failed:retry), інакше замовлення не дійде в CRM; doctor показує queue.failed_msgs.
 * Тип повідомлення Doctrine-транспорт пише в headers (JSON, ключ type); час — UTC.
 */
#[AsMessageHandler]
final class PruneFailedMessagesHandler
{
    /** фрагмент типу в headers.type (клас з екранованими слешами в JSON) */
    public const string TOKEN_MESSAGE = 'SendCustomerAuthMail';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.messenger_failed_days%')] private readonly int $days,
    ) {
    }

    public function __invoke(PruneFailedMessages $message): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = ? AND created_at < ? AND headers LIKE ?',
            ['failed', (new DateTimeImmutable('-'.$this->days.' days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'), '%'.self::TOKEN_MESSAGE.'%'],
        );
    }
}

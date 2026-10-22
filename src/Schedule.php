<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App;

use App\Core\Ai\Message\PruneAiUsage;
use App\Core\Audit\Message\PruneAdminAudit;
use App\Core\Customer\Message\PurgeUnverifiedCustomers;
use App\Core\Demo\Message\ResetDemoCustomers;
use App\Core\Order\Message\PurgeOrderAttachments;
use App\Core\Seo\Message\PruneNotFoundLog;
use App\Core\Shared\Messenger\Message\PruneFailedMessages;
use App\Core\Shipping\NovaPoshta\Message\SyncNovaPoshtaDirectory;
use App\Site\Feed\Message\GenerateMerchantFeeds;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Розклад (symfony/scheduler): воркер `messenger:consume async scheduler_default` диспатчить повідомлення за cron,
 * самі задачі виконує async-воркер (routing у messenger.yaml). Stateful + lock — пропущені запуски наздоганяються один раз
 * і два воркери не дублюють один тік.
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockFactory $lockFactory,
        #[Autowire('%app.nova_poshta_sync_cron%')] private readonly string $novaPoshtaCron = '30 3 * * *',
        #[Autowire('%app.merchant_feed_interval%')] private readonly string $merchantFeedInterval = '6 hours',
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler-default', 60))
            // довідники Нової пошти — щодня вночі (без ключа хендлер тихо виходить)
            ->add(RecurringMessage::cron($this->novaPoshtaCron, new SyncNovaPoshtaDirectory()))
            // журнал змін адмінки (E15): рядки, старші за app.admin_audit_months — щоночі
            ->add(RecurringMessage::cron('40 3 * * *', new PruneAdminAudit()))
            // журнал 404 (E3): старі й понад ліміт рядки — щоночі
            ->add(RecurringMessage::cron('45 3 * * *', new PruneNotFoundLog()))
            // кабінет (E12): неперевірені реєстрації старші за 7 днів і прострочені токени листів
            ->add(RecurringMessage::cron('50 3 * * *', new PurgeUnverifiedCustomers()))
            // вкладення заявок (/request): файли старші за app.order_attachment_retention_days — щоночі, рядки лишаються
            ->add(RecurringMessage::cron('05 4 * * *', new PurgeOrderAttachments()))
            // черга failed: посилання з токенами листів не лежать безстроково (консиліум безпеки 10.10.2026, п. 10)
            ->add(RecurringMessage::cron('55 3 * * *', new PruneFailedMessages()))
            // журнал використання AI (E15 BYOK): старше за app.ai_usage_months
            ->add(RecurringMessage::cron('35 3 * * *', new PruneAiUsage()))
            // публічне демо: демо-покупці щоночі як нові (без app.demo.customer_password хендлер тихо виходить)
            ->add(RecurringMessage::cron('15 4 * * *', new ResetDemoCustomers()))
            // товарні фіди Google Merchant і Meta (Ф3): крім оновлення після імпорту — за розкладом (ціни/залишки з вебхуків)
            // (у черзі feeds: повідомлення з scheduler_default інакше обробляються тим самим воркером, що й async)
            ->add(RecurringMessage::every($this->merchantFeedInterval, new RedispatchMessage(new GenerateMerchantFeeds(), 'feeds')));
    }
}

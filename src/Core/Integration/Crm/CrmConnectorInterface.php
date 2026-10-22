<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use App\Core\Integration\Crm\Exception\CrmNotSupportedException;
use App\Core\Integration\Crm\Exception\WebhookRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Контракт CRM (ADR Rev 3): ядро не знає конкретної CRM. Реалізації — теговані сервіси з #[AsTaggedItem(index: '<код>')],
 * активна обирається CRM_CONNECTOR (CrmConnectorRegistry). Зовнішні id — лише в ExternalRef (system = код конектора).
 */
#[AutoconfigureTag('app.crm_connector')]
interface CrmConnectorInterface
{
    /** Код системи для ExternalRef.system і CRM_CONNECTOR. */
    public function getSystem(): string;

    public function capabilities(): CrmCapabilities;

    /** @throws CrmNotSupportedException якщо capabilities()->pushOrder === false */
    public function pushOrder(OrderExport $order): CrmOrderRef;

    /**
     * Залишки з CRM: усі або лише вказані sku. Реалізація сама пагінує й тримає rate limit.
     *
     * @param iterable<string> $skus порожньо — усі
     *
     * @return iterable<StockLevel>
     */
    public function pullStock(iterable $skus = []): iterable;

    /**
     * Розібрати вебхук CRM: перевірка токена/формату — тут; застосування — у StockLevelApplier.
     *
     * @return iterable<StockLevel|OrderStatusChange>
     *
     * @throws WebhookRejectedException
     */
    public function handleWebhook(Request $request): iterable;
}

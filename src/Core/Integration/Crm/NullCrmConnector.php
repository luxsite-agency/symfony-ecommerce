<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use App\Core\Integration\Crm\Exception\WebhookRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request;

/** Магазин без CRM (ADR Rev 3): master залишків і цін — сайт (адмінка + фіди), локальне списання остаточне. */
#[AsTaggedItem(index: 'none')]
final class NullCrmConnector implements CrmConnectorInterface
{
    public function getSystem(): string
    {
        return 'none';
    }

    public function capabilities(): CrmCapabilities
    {
        return new CrmCapabilities();
    }

    public function pushOrder(OrderExport $order): CrmOrderRef
    {
        return CrmOrderRef::none('none');
    }

    public function pullStock(iterable $skus = []): iterable
    {
        return [];
    }

    public function handleWebhook(Request $request): iterable
    {
        throw new WebhookRejectedException('CRM не налаштовано', 404);
    }
}

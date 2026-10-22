<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Активний конектор за CRM_CONNECTOR (none|keycrm|…); невідомий код — помилка конфігурації, не мовчазний Null. */
final class CrmConnectorRegistry
{
    /** @var array<string, CrmConnectorInterface> */
    private array $connectors = [];

    /** @param iterable<CrmConnectorInterface> $connectors */
    public function __construct(
        #[AutowireIterator('app.crm_connector')] iterable $connectors,
        #[Autowire('%env(CRM_CONNECTOR)%')] private readonly string $activeCode,
    ) {
        foreach ($connectors as $connector) {
            $this->connectors[$connector->getSystem()] = $connector;
        }
    }

    public function active(): CrmConnectorInterface
    {
        return $this->get($this->activeCode);
    }

    /**
     * Чи приймає активна CRM вебхук залишків (рішення власника 11.10.2026: при CRM_CONNECTOR != keycrm маршрут /webhooks/crm/…
     * вимкнений, 404 без запису в журнал). Невідомий код тут — теж «вимкнено», а не 500 (перевірка CRM_CONNECTOR у doctor — у беклозі).
     */
    public function webhookEnabled(): bool
    {
        return ($this->connectors[$this->activeCode] ?? null)?->capabilities()->stockWebhook ?? false;
    }

    public function get(string $code): CrmConnectorInterface
    {
        return $this->connectors[$code] ?? throw new InvalidArgumentException(\sprintf('Невідомий CRM-конектор "%s"; доступні: %s', $code, implode(', ', array_keys($this->connectors))));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->connectors);
    }
}

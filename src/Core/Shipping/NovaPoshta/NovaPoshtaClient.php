<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

use App\Core\Shipping\NovaPoshta\Exception\NovaPoshtaApiException;
use App\Core\Shipping\NovaPoshta\Exception\NovaPoshtaDisabledException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тонкий клієнт API Нової пошти v2.0 (JSON): лише довідники Address.getCities / Address.getWarehouses посторінково.
 * Ключ — env NOVA_POSHTA_API_KEY; порожній ключ = інтеграцію вимкнено (isEnabled), виклики кидають NovaPoshtaDisabledException.
 * У тестах — MockHttpClient.
 */
final class NovaPoshtaClient
{
    public const string ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(NOVA_POSHTA_API_KEY)%')] private readonly string $apiKey = '',
        private readonly string $endpoint = self::ENDPOINT,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /** @return list<array<string, mixed>> сторінка міст (Ref, Description, DescriptionRu, AreaDescription, SettlementTypeDescription, …) */
    public function cities(int $page, int $limit): array
    {
        return $this->call('Address', 'getCities', ['Page' => $page, 'Limit' => $limit]);
    }

    /** @return list<array<string, mixed>> сторінка відділень усіх міст (Ref, CityRef, Number, Description, CategoryOfWarehouse, WarehouseStatus, …) */
    public function warehouses(int $page, int $limit): array
    {
        return $this->call('Address', 'getWarehouses', ['Page' => $page, 'Limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return list<array<string, mixed>>
     */
    private function call(string $model, string $method, array $properties): array
    {
        if (!$this->isEnabled()) {
            throw new NovaPoshtaDisabledException('NOVA_POSHTA_API_KEY не задано — довідники Нової пошти вимкнено');
        }
        try {
            /** @var array{success?: bool, data?: list<array<string, mixed>>, errors?: list<string>} $body */
            $body = $this->http->request('POST', $this->endpoint, [
                'json' => ['apiKey' => $this->apiKey, 'modelName' => $model, 'calledMethod' => $method, 'methodProperties' => $properties],
                'timeout' => 60,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new NovaPoshtaApiException(\sprintf('Нова пошта %s.%s: %s', $model, $method, $e->getMessage()), 0, $e);
        }
        if (true !== ($body['success'] ?? false)) {
            throw new NovaPoshtaApiException(\sprintf('Нова пошта %s.%s: %s', $model, $method, implode('; ', $body['errors'] ?? ['success=false'])));
        }

        return $body['data'] ?? [];
    }
}

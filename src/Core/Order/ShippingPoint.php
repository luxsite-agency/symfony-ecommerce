<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

/**
 * Снепшот обраної точки доставки (відділення/поштомат НП) у замовленні: refs для CRM і назви на момент оформлення.
 * Зберігається JSON-ом у sales_order.shipping_point; місто/адреса текстом дублюються в shipping_city/shipping_address.
 */
final readonly class ShippingPoint
{
    public const string PROVIDER_NOVA_POSHTA = 'nova_poshta';

    public function __construct(
        public string $provider,
        public string $cityRef,
        public string $cityName,
        public string $warehouseRef,
        public string $warehouseNumber,
        public string $warehouseName,
        public string $warehouseType,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['provider' => $this->provider, 'city_ref' => $this->cityRef, 'city_name' => $this->cityName, 'warehouse_ref' => $this->warehouseRef, 'warehouse_number' => $this->warehouseNumber, 'warehouse_name' => $this->warehouseName, 'warehouse_type' => $this->warehouseType];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['provider'] ?? ''), (string) ($data['city_ref'] ?? ''), (string) ($data['city_name'] ?? ''), (string) ($data['warehouse_ref'] ?? ''), (string) ($data['warehouse_number'] ?? ''), (string) ($data['warehouse_name'] ?? ''), (string) ($data['warehouse_type'] ?? ''));
    }
}

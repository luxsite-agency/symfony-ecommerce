<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Shared\Quantity\Quantity;
use InvalidArgumentException;

/**
 * Варіант у рядку імпорту (ADR D2/Rev 2): externalId — ключ зіставлення з джерелом, sku — унікальний код магазину.
 * Ціни в мінорних одиницях; перекреслена ціна, що не більша за ціну, відкидається; від'ємний залишок → 0.
 */
final readonly class VariantImportRow
{
    public string $stock;
    public ?int $oldPriceMinor;

    /**
     * @param array<string, string> $axes  код осі → код значення (колір → white)
     * @param array<string, mixed>  $extra поля постачальника, які ядро не мапить (лишаються в payload)
     */
    public function __construct(
        public string $externalId,
        public string $sku,
        public int $priceMinor,
        public string $currency,
        string $stock = '0',
        ?int $oldPriceMinor = null,
        public ?string $barcode = null,
        public array $axes = [],
        public StockPolicy $stockPolicy = StockPolicy::InStock,
        public SalesUnit $unit = SalesUnit::Piece,
        public bool $active = true,
        public array $extra = [],
    ) {
        if ('' === trim($externalId) || '' === trim($sku)) {
            throw new InvalidImportRowException('Варіант без externalId або sku');
        }
        if (mb_strlen($sku) > 64 || \strlen($externalId) > 128) {
            throw new InvalidImportRowException(\sprintf('Задовгий sku/externalId: "%s"/"%s"', $sku, $externalId));
        }
        if ($priceMinor < 0) {
            throw new InvalidImportRowException(\sprintf('Від\'ємна ціна варіанта %s', $sku));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidImportRowException(\sprintf('Не код валюти: "%s"', $currency));
        }
        try {
            $normalized = Quantity::normalize($stock);
        } catch (InvalidArgumentException $e) {
            throw new InvalidImportRowException(\sprintf('Залишок варіанта %s: %s', $sku, $e->getMessage()), 0, $e);
        }
        $this->stock = -1 === bccomp($normalized, '0', 3) ? '0.000' : $normalized;
        $this->oldPriceMinor = null !== $oldPriceMinor && $oldPriceMinor > $priceMinor ? $oldPriceMinor : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'sku' => $this->sku,
            'priceMinor' => $this->priceMinor,
            'oldPriceMinor' => $this->oldPriceMinor,
            'currency' => $this->currency,
            'stock' => $this->stock,
            'barcode' => $this->barcode,
            'axes' => $this->axes,
            'stockPolicy' => $this->stockPolicy->value,
            'unit' => $this->unit->value,
            'active' => $this->active,
            'extra' => $this->extra,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) ($data['externalId'] ?? ''),
            sku: (string) ($data['sku'] ?? ''),
            priceMinor: (int) ($data['priceMinor'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            stock: (string) ($data['stock'] ?? '0'),
            oldPriceMinor: isset($data['oldPriceMinor']) ? (int) $data['oldPriceMinor'] : null,
            barcode: isset($data['barcode']) ? (string) $data['barcode'] : null,
            axes: \is_array($data['axes'] ?? null) ? $data['axes'] : [],
            stockPolicy: StockPolicy::tryFrom((string) ($data['stockPolicy'] ?? '')) ?? StockPolicy::InStock,
            unit: SalesUnit::tryFrom((string) ($data['unit'] ?? '')) ?? SalesUnit::Piece,
            active: (bool) ($data['active'] ?? true),
            extra: \is_array($data['extra'] ?? null) ? $data['extra'] : [],
        );
    }

    /** Те, від чого залежить контент (не ціна/залишок — вони йдуть batch-UPDATE-ом). */
    public function contentFingerprint(): string
    {
        return json_encode([$this->externalId, $this->sku, $this->barcode, $this->axes, $this->stockPolicy->value, $this->unit->value, $this->active], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}

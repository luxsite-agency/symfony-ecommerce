<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use DateTimeImmutable;

/**
 * Рядок імпорту картки (ADR D10): спільний DTO для всіх адаптерів (фіди постачальників, CRM). Ядро не знає формату джерела.
 * externalId — ключ картки в джерелі (зберігається в ExternalRef system=feed_<джерело>); варіанти — за власними externalId/sku.
 */
final readonly class ProductImportRow
{
    /** @var list<VariantImportRow> */
    public array $variants;

    /**
     * @param array<string, string>                        $names                локаль → назва (хоча б одна)
     * @param array<string, string|null>                   $descriptions         локаль → опис
     * @param list<list<string>>                           $categoryPaths        шляхи категорій назвами від кореня: [['Двері', 'Міжкімнатні']]
     * @param array<string, list<list<string>>>            $categoryTranslations мова → ті самі шляхи іншою мовою (паралельно categoryPaths)
     * @param array<string, string|list<string>|bool|null> $attributes           код характеристики → значення (число/текст як рядок, multiselect — список)
     * @param list<string>                                 $mediaUrls
     * @param iterable<VariantImportRow>                   $variants
     */
    public function __construct(
        public string $externalId,
        public array $names,
        iterable $variants,
        public array $descriptions = [],
        public ?string $brand = null,
        public array $categoryPaths = [],
        public array $attributes = [],
        public array $mediaUrls = [],
        public ?DateTimeImmutable $sourceUpdatedAt = null,
        public ProductKind $kind = ProductKind::Goods,
        public PriceMode $priceMode = PriceMode::Fixed,
        public bool $active = true,
        public array $categoryTranslations = [],
    ) {
        if ('' === trim($externalId) || \strlen($externalId) > 128) {
            throw new InvalidImportRowException('Картка без externalId (або він довший за 128)');
        }
        $list = [];
        foreach ($variants as $variant) {
            $list[] = $variant;
        }
        if ([] === $list) {
            throw new InvalidImportRowException(\sprintf('Картка %s без варіантів', $externalId));
        }
        $currencies = array_unique(array_map(static fn (VariantImportRow $v): string => $v->currency, $list));
        if (\count($currencies) > 1) {
            throw new InvalidImportRowException(\sprintf('Картка %s: варіанти в різних валютах (%s)', $externalId, implode(', ', $currencies)));
        }
        if ([] === array_filter($names, static fn (string $n): bool => '' !== trim($n))) {
            throw new InvalidImportRowException(\sprintf('Картка %s без назви', $externalId));
        }
        $this->variants = $list;
    }

    public function currency(): string
    {
        return $this->variants[0]->currency;
    }

    /** Хеш контенту картки без цін і залишків: збіг з Product.sourceHash → ORM не чіпаємо. */
    public function contentHash(): string
    {
        return sha1(json_encode([
            $this->names, $this->descriptions, $this->brand, $this->categoryPaths, $this->categoryTranslations, $this->attributes, $this->mediaUrls,
            $this->kind->value, $this->priceMode->value, $this->active,
            array_map(static fn (VariantImportRow $v): string => $v->contentFingerprint(), $this->variants),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'names' => $this->names,
            'descriptions' => $this->descriptions,
            'brand' => $this->brand,
            'categoryPaths' => $this->categoryPaths,
            'categoryTranslations' => $this->categoryTranslations,
            'attributes' => $this->attributes,
            'mediaUrls' => $this->mediaUrls,
            'sourceUpdatedAt' => $this->sourceUpdatedAt?->format(\DATE_ATOM),
            'kind' => $this->kind->value,
            'priceMode' => $this->priceMode->value,
            'active' => $this->active,
            'variants' => array_map(static fn (VariantImportRow $v): array => $v->toArray(), $this->variants),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) ($data['externalId'] ?? ''),
            names: \is_array($data['names'] ?? null) ? $data['names'] : [],
            variants: array_map(static fn (array $v): VariantImportRow => VariantImportRow::fromArray($v), \is_array($data['variants'] ?? null) ? $data['variants'] : []),
            descriptions: \is_array($data['descriptions'] ?? null) ? $data['descriptions'] : [],
            brand: isset($data['brand']) ? (string) $data['brand'] : null,
            categoryPaths: \is_array($data['categoryPaths'] ?? null) ? $data['categoryPaths'] : [],
            attributes: \is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            mediaUrls: \is_array($data['mediaUrls'] ?? null) ? $data['mediaUrls'] : [],
            sourceUpdatedAt: isset($data['sourceUpdatedAt']) ? new DateTimeImmutable((string) $data['sourceUpdatedAt']) : null,
            kind: ProductKind::tryFrom((string) ($data['kind'] ?? '')) ?? ProductKind::Goods,
            priceMode: PriceMode::tryFrom((string) ($data['priceMode'] ?? '')) ?? PriceMode::Fixed,
            active: (bool) ($data['active'] ?? true),
            categoryTranslations: \is_array($data['categoryTranslations'] ?? null) ? $data['categoryTranslations'] : [],
        );
    }
}

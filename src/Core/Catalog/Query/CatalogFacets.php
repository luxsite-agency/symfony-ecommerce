<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/**
 * Лічильники фасетного блоку (ADR Rev 9): кількість КАРТОК (не варіантів). Для обраного атрибута лічильники рахуються
 * без його власного фільтра (disjunctive: «що буде, якщо додати ще це значення»), для решти — з усіма фільтрами.
 *
 * Лічильник null (ADR Rev 11 п. 5) — «невідомо»: лічильники вимкнені в налаштуваннях, база фасета більша за поріг N
 * або спрацював rate limit. Значення тоді є (з зведення категорії без фільтрів), але без цифри і не ховається.
 * Ключа нема — значення в категорії нема зовсім.
 */
final readonly class CatalogFacets
{
    /**
     * @param array<int, array<int, int|null>> $options  attribute_id → option_id → картки
     * @param array<int, array<int, int|null>> $booleans attribute_id → 0|1 → картки
     * @param array<int, NumberRange>          $numbers  attribute_id → MIN/MAX значень
     * @param array<int, int|null>             $brands   brand_id → картки
     * @param PriceRange|null                  $price    MIN/MAX ціни (fixed/from) без власного фільтра ціни; null — цін нема
     * @param int|null                         $inStock  карток «в наявності» з рештою фільтрів
     * @param array<string, int>               $letters  перша літера назви (верхній регістр) → картки, без власного фільтра літери; [] — не рахувались
     */
    public function __construct(
        public array $options = [],
        public array $booleans = [],
        public array $numbers = [],
        public array $brands = [],
        public ?PriceRange $price = null,
        public ?int $inStock = 0,
        public array $letters = [],
    ) {
    }

    /** null — невідомо або значення нема (див. hasOption). */
    public function optionCount(int $attributeId, int $optionId): ?int
    {
        return $this->options[$attributeId][$optionId] ?? null;
    }

    public function hasOption(int $attributeId, int $optionId): bool
    {
        return \array_key_exists($optionId, $this->options[$attributeId] ?? []);
    }

    public function hasBoolean(int $attributeId, bool $value): bool
    {
        return isset($this->booleans[$attributeId]) && \array_key_exists((int) $value, $this->booleans[$attributeId]);
    }

    /**
     * Ті самі ключі, лічильники — null.
     *
     * @template K of int
     *
     * @param array<K, int|null> $counts
     *
     * @return array<K, null>
     */
    public static function unknown(array $counts): array
    {
        return array_map(static fn (): null => null, $counts);
    }

    /**
     * Для JSON зведення категорії: ключі — рядки, діапазони — пари.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'options' => $this->options,
            'booleans' => $this->booleans,
            'numbers' => array_map(static fn (NumberRange $r): array => [$r->min, $r->max], $this->numbers),
            'brands' => $this->brands,
            'price' => null === $this->price ? null : [$this->price->min, $this->price->max],
            'in_stock' => $this->inStock,
            'letters' => $this->letters,
        ];
    }

    /** @param array<string, mixed> $data з toArray() після json_decode(…, true) */
    public static function fromArray(array $data): self
    {
        $counts = static function (mixed $map): array {
            $result = [];
            foreach (\is_array($map) ? $map : [] as $key => $count) {
                $result[(int) $key] = null === $count ? null : (int) $count;
            }

            return $result;
        };
        $nested = static function (mixed $map) use ($counts): array {
            $result = [];
            foreach (\is_array($map) ? $map : [] as $key => $inner) {
                $result[(int) $key] = $counts($inner);
            }

            return $result;
        };
        $numbers = [];
        foreach (\is_array($data['numbers'] ?? null) ? $data['numbers'] : [] as $key => $pair) {
            if (\is_array($pair) && 2 === \count($pair)) {
                $numbers[(int) $key] = new NumberRange(null === $pair[0] ? null : (string) $pair[0], null === $pair[1] ? null : (string) $pair[1]);
            }
        }
        $price = $data['price'] ?? null;

        return new self(
            $nested($data['options'] ?? []),
            $nested($data['booleans'] ?? []),
            $numbers,
            $counts($data['brands'] ?? []),
            \is_array($price) && 2 === \count($price) ? new PriceRange((int) $price[0], (int) $price[1]) : null,
            isset($data['in_stock']) ? (int) $data['in_stock'] : null,
            self::letterCounts($data['letters'] ?? null),
        );
    }

    /** @return array<string, int> */
    private static function letterCounts(mixed $map): array
    {
        $result = [];
        foreach (\is_array($map) ? $map : [] as $letter => $count) {
            $result[(string) $letter] = (int) $count;
        }

        return $result;
    }
}

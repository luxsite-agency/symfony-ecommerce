<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Query\CatalogResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Фасетний блок для шаблону: характеристики категорії в порядку CategoryFacetResolver, потім бренди, ціна і «в наявності».
 * Кожне значення несе лічильник CatalogFacets і готовий query для посилання (увімкнути/вимкнути — без JS, звичайні
 * GET-посилання, які індексуються як noindex-сторінки). Значення з нульовим лічильником ховаються, якщо не обрані.
 * Лічильник null (ADR Rev 11: вимкнено в налаштуваннях, база більша за поріг, rate limit) — значення є в категорії,
 * показується без цифри і не ховається.
 *
 * @phpstan-type Item array{label: string, count: int|null, selected: bool, query: array<string, mixed>}
 * @phpstan-type Block array{kind: string, code: string, label: string, unit: ?string, selected: bool, clear: array<string, mixed>, items: list<Item>, range: array{min: ?string, max: ?string, from: ?string, to: ?string}|null, hidden: array<string, string>}
 */
final readonly class FacetBlockBuilder
{
    public const string DOMAIN = 'site_catalog';

    public function __construct(private CatalogLabels $labels, private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<int, array{label: string, options: list<array{id: int, code: string, label: string}>}> $labels CatalogLabels::attributes()
     *
     * @return list<Block>
     */
    public function build(CatalogResult $result, ListingParams $params, array $labels, string $locale): array
    {
        $facets = $result->facets;
        $blocks = [];
        foreach ($result->definitions as $definition) {
            $id = $definition->attributeId;
            $code = $definition->code;
            $block = [
                'kind' => 'options', 'code' => $code, 'label' => $labels[$id]['label'] ?? $code, 'unit' => null === $definition->unit ? null : $this->translator->trans($definition->unit, [], 'units', $locale), // «мм» → «mm» (units)
                'selected' => isset($params->values[$code]) || isset($params->ranges[$code]),
                'clear' => $params->withoutAttribute($code)->toQuery(), 'items' => [], 'range' => null, 'hidden' => [],
            ];
            if ($definition->hasOptions()) {
                foreach ($labels[$id]['options'] ?? [] as $option) {
                    $count = $facets->optionCount($id, $option['id']);
                    $selected = $params->isValueSelected($code, $option['code']);
                    if (self::shown($facets->hasOption($id, $option['id']), $count, $selected)) {
                        $block['items'][] = ['label' => $option['label'], 'count' => $count, 'selected' => $selected, 'query' => $params->toggleValue($code, $option['code'])->toQuery()];
                    }
                }
            } elseif ($definition->isBoolean()) {
                $block['kind'] = 'boolean';
                foreach ([true, false] as $flag) {
                    $count = $facets->booleans[$id][(int) $flag] ?? null;
                    $selected = $params->isValueSelected($code, $flag ? '1' : '0');
                    if (self::shown($facets->hasBoolean($id, $flag), $count, $selected)) {
                        $label = $this->translator->trans($flag ? 'catalog.flag_yes' : 'catalog.flag_no', [], self::DOMAIN, $locale);
                        $block['items'][] = ['label' => $label, 'count' => $count, 'selected' => $selected, 'query' => $params->toggleFlag($code, $flag)->toQuery()];
                    }
                }
            } elseif ($definition->isNumber()) {
                $block['kind'] = 'number';
                $available = $facets->numbers[$id] ?? null;
                $chosen = $params->ranges[$code] ?? null;
                if (null === $available && null === $chosen) {
                    continue;
                }
                $block['range'] = ['min' => $available?->min, 'max' => $available?->max, 'from' => $chosen?->min, 'to' => $chosen?->max];
                $block['hidden'] = $params->hiddenFields(exceptAttribute: $code);
                $blocks[] = $block;
                continue;
            }
            if ([] !== $block['items']) {
                $blocks[] = $block;
            }
        }

        $blocks[] = $this->brands($result, $params, $locale);
        $blocks[] = $this->price($result, $params, $locale);
        $blocks[] = [
            'kind' => 'in_stock', 'code' => 'in_stock', 'label' => $this->translator->trans('catalog.facet.in_stock', [], self::DOMAIN, $locale), 'unit' => null,
            'selected' => $params->inStock, 'clear' => $params->inStock ? $params->toggleInStock()->toQuery() : $params->toQuery(),
            'items' => [['label' => $this->translator->trans('catalog.facet.in_stock_only', [], self::DOMAIN, $locale), 'count' => $facets->inStock, 'selected' => $params->inStock, 'query' => $params->toggleInStock()->toQuery()]],
            'range' => null, 'hidden' => [],
        ];

        return array_values(array_filter($blocks, static fn (array $b): bool => [] !== $b['items'] || null !== $b['range']));
    }

    /** Значення є в наборі (або невідомо, скільки) чи обране — показати; нуль і не обране — сховати. */
    private static function shown(bool $known, ?int $count, bool $selected): bool
    {
        return $selected || ($known && (null === $count || $count > 0));
    }

    /** @return Block */
    private function brands(CatalogResult $result, ListingParams $params, string $locale): array
    {
        $items = [];
        $known = [];
        foreach ($this->labels->brandsByIds(array_keys($result->facets->brands)) as $id => $brand) {
            $known[] = $brand['slug'];
            $selected = \in_array($brand['slug'], $params->brands, true);
            $items[] = ['label' => $brand['name'], 'count' => $result->facets->brands[$id] ?? null, 'selected' => $selected, 'query' => $params->toggleBrand($brand['slug'])->toQuery()];
        }
        foreach (array_diff($params->brands, $known) as $slug) {
            $items[] = ['label' => $slug, 'count' => null, 'selected' => true, 'query' => $params->toggleBrand($slug)->toQuery()];
        }

        return [
            'kind' => 'brand', 'code' => 'brand', 'label' => $this->translator->trans('catalog.facet.brand', [], self::DOMAIN, $locale), 'unit' => null,
            'selected' => [] !== $params->brands, 'clear' => $params->withoutBrands()->toQuery(), 'items' => $items, 'range' => null, 'hidden' => [],
        ];
    }

    /** @return Block */
    private function price(CatalogResult $result, ListingParams $params, string $locale): array
    {
        $available = $result->facets->price;
        $range = null;
        if (null !== $available || !$params->price->isEmpty()) {
            $range = [
                'min' => null !== $available?->min ? ListingFilterFactory::major($available->min) : null,
                'max' => null !== $available?->max ? ListingFilterFactory::major($available->max) : null,
                'from' => $params->price->min, 'to' => $params->price->max,
            ];
        }

        return [
            'kind' => 'price', 'code' => 'price', 'label' => $this->translator->trans('catalog.facet.price', [], self::DOMAIN, $locale), 'unit' => null,
            'selected' => !$params->price->isEmpty(), 'clear' => $params->withoutPrice()->toQuery(), 'items' => [], 'range' => $range,
            'hidden' => $params->hiddenFields(exceptPrice: true),
        ];
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\BulkOrder;

use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Catalog\QuantityRules;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Звірка списку з каталогом (E13 PR-7): однакові SKU складаються, варіант шукається за SKU одним запитом, недоступний
 * (неактивний товар чи варіант, не продається) — unavailable, правила кількості варіанта — below_min/invalid_step,
 * ціна — фасад Pricing::line за групою покупця зі значеннями опцій за замовчуванням (обовʼязкова група без них —
 * needs_options). Той самий фасад рахує кошик і PlaceOrder, тож сума в таблиці дорівнює сумі в кошику.
 */
final class BulkOrderResolver
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Pricing $pricing)
    {
    }

    /**
     * @param list<BulkOrderLine> $lines
     *
     * @return list<BulkOrderRow> у порядку першої появи SKU
     */
    public function resolve(array $lines, ?CustomerGroup $group, string $locale): array
    {
        /** @var array<string, BulkOrderLine> $merged */
        $merged = [];
        foreach ($lines as $line) {
            $key = mb_strtolower($line->sku);
            $previous = $merged[$key] ?? null;
            if (null === $previous) {
                $merged[$key] = $line;
            } elseif (null === $previous->error && null === $line->error) {
                $merged[$key] = new BulkOrderLine($previous->line, $previous->sku, Quantity::normalize(bcadd($previous->quantity, $line->quantity, 3)));
            } elseif (null === $previous->error) {
                $merged[$key] = new BulkOrderLine($previous->line, $previous->sku, $previous->quantity, $line->error);
            }
        }
        // один запит з усім, що читає Pricing::line для значень опцій за замовчуванням (як GroupPriceOverlay::prices):
        // товар, переклади, пороги з групами, групи опцій, категорії (цілі акцій) — інакше 4–6 лінивих запитів на рядок
        $skus = array_map(static fn (BulkOrderLine $l): string => $l->sku, array_values($merged));
        /** @var list<ProductVariant> $found */
        $found = [] === $skus ? [] : $this->em->createQueryBuilder()->select('v', 'p', 'pt', 't', 'tg', 'og', 'c')->from(ProductVariant::class, 'v')
            ->join('v.product', 'p')->leftJoin('p.translations', 'pt')->leftJoin('v.priceTiers', 't')->leftJoin('t.customerGroup', 'tg')
            ->leftJoin('p.optionGroups', 'og')->leftJoin('p.categories', 'c')
            ->where('v.sku IN (:skus)')->setParameter('skus', $skus)->getQuery()->getResult();
        $variants = [];
        foreach ($found as $variant) {
            $variants[mb_strtolower($variant->getSku())] = $variant;
        }
        $productIds = array_values(array_unique(array_map(static fn (ProductVariant $v): int => (int) $v->getProduct()->getId(), $found)));
        if ([] !== $productIds) {
            // значення груп опцій, їхні підписи й варіанти-компоненти з порогами — те, що OptionPriceCalculator читає для дефолтів
            $this->em->createQueryBuilder()->select('og', 'ogt', 'ov', 'ovt', 'cv', 'cvt')->from(OptionGroup::class, 'og')
                ->leftJoin('og.translations', 'ogt')->leftJoin('og.values', 'ov')->leftJoin('ov.translations', 'ovt')
                ->leftJoin('ov.componentVariant', 'cv')->leftJoin('cv.priceTiers', 'cvt')
                ->where('og.product IN (:ids)')->setParameter('ids', $productIds)->getQuery()->getResult();
        }
        $rows = [];
        foreach ($merged as $key => $line) {
            $variant = $variants[$key] ?? null;
            if (null === $variant) {
                $rows[] = new BulkOrderRow($line->line, $line->sku, $line->quantity, 'header' === $line->error ? 'header' : 'not_found');
                continue;
            }
            $name = $variant->getProduct()->getTranslation($locale)?->getName() ?? $variant->getSku();
            if (null !== $line->error) {
                // «заголовок» з реальним SKU — це рядок з нечисловою кількістю, не заголовок
                $rows[] = new BulkOrderRow($line->line, $variant->getSku(), $line->quantity, 'header' === $line->error ? 'invalid_quantity' : $line->error, $variant, $name);
                continue;
            }
            if (!$variant->isOrderable() || !$variant->getProduct()->isActive()) {
                $rows[] = new BulkOrderRow($line->line, $variant->getSku(), $line->quantity, 'unavailable', $variant, $name);
                continue;
            }
            $problem = QuantityRules::problem($variant, $line->quantity);
            if (null !== $problem) {
                $rows[] = new BulkOrderRow($line->line, $variant->getSku(), $line->quantity, 'invalid' === $problem ? 'invalid_quantity' : $problem, $variant, $name);
                continue;
            }
            try {
                $quote = $this->pricing->line($variant, $line->quantity, null, $group, $locale);
            } catch (InvalidOptionsException) {
                $rows[] = new BulkOrderRow($line->line, $variant->getSku(), $line->quantity, 'needs_options', $variant, $name);
                continue;
            }
            $rows[] = new BulkOrderRow($line->line, $variant->getSku(), $line->quantity, BulkOrderRow::OK, $variant, $name, $quote);
        }

        return $rows;
    }

    /** @param list<BulkOrderRow> $rows */
    public static function total(array $rows, string $currency): Money
    {
        $total = Money::zero($currency);
        foreach ($rows as $row) {
            $lineTotal = $row->lineTotal();
            if (BulkOrderRow::OK === $row->status && null !== $lineTotal && $lineTotal->currency === $currency) {
                $total = $total->add($lineTotal);
            }
        }

        return $total;
    }
}

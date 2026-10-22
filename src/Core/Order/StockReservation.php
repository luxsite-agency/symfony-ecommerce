<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Order\Exception\OutOfStockException;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\DBAL\Connection;

/**
 * Резерв залишку при оформленні (ADR Settled/D7): атомарний UPDATE … WHERE stock >= :q для політики in_stock;
 * made_to_order і not_tracked не списують. Повернення — лише при скасуванні (OrderTransitionService).
 */
final class StockReservation
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return string скільки списано (0 для політик без обліку) */
    public function reserve(ProductVariant $variant, string $quantity): string
    {
        $quantity = Quantity::positive($quantity);
        if (StockPolicy::InStock !== $variant->getStockPolicy()) {
            return '0.000';
        }
        $affected = $this->db->executeStatement(
            'UPDATE catalog_product_variant SET stock = stock - ?, updated_at = NOW() WHERE id = ? AND stock >= ?',
            [$quantity, $variant->getId(), $quantity],
        );
        if (1 !== $affected) {
            throw new OutOfStockException($variant->getSku(), $quantity);
        }

        return $quantity;
    }

    public function release(ProductVariant $variant, string $quantity): void
    {
        $quantity = Quantity::nonNegative($quantity);
        if (0 === bccomp($quantity, '0', 3)) {
            return;
        }
        $this->db->executeStatement('UPDATE catalog_product_variant SET stock = stock + ?, updated_at = NOW() WHERE id = ?', [$quantity, $variant->getId()]);
    }
}

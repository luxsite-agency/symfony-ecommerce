<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Viewed;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * «Переглянуті» покупця в БД (E12 PR-10): запис на перегляді картки залогіненим, не більше LIMIT останніх на покупця
 * (старіші видаляються тут же); порядок — за id (кожен перегляд — новий рядок). Гість нічого не пише: картка товару без сесії лишається кешованою.
 */
final class RecentlyViewed
{
    public const int LIMIT = 20;

    public function __construct(private readonly Connection $db)
    {
    }

    public function record(Customer $customer, Product $product): void
    {
        $customerId = $customer->getId();
        $productId = $product->getId();
        if (null === $customerId || null === $productId) {
            return;
        }
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');
        // REPLACE (MySQL): повторний перегляд — новий рядок з новим id, тож порядок «останні першими» точний і в межах
        // секунди (viewed_at — DATETIME без часток)
        $this->db->executeStatement(
            'REPLACE INTO customer_viewed (customer_id, product_id, viewed_at) VALUES (?, ?, ?)',
            [$customerId, $productId, $now],
        );
        $stale = $this->db->fetchFirstColumn(
            'SELECT id FROM customer_viewed WHERE customer_id = ? ORDER BY id DESC LIMIT 1000 OFFSET '.self::LIMIT,
            [$customerId],
        );
        if ([] !== $stale) {
            $this->db->executeStatement('DELETE FROM customer_viewed WHERE id IN (?)', [$stale], [ArrayParameterType::INTEGER]);
        }
    }

    /** @return list<int> id товарів, останні переглянуті першими, без $excludeProductId (поточна картка) */
    public function productIds(Customer $customer, ?int $excludeProductId = null, int $limit = self::LIMIT): array
    {
        $ids = $this->db->fetchFirstColumn(
            'SELECT product_id FROM customer_viewed WHERE customer_id = ? ORDER BY id DESC LIMIT '.self::LIMIT,
            [$customer->getId()],
            [ParameterType::INTEGER],
        );
        $ids = array_map(intval(...), $ids);
        if (null !== $excludeProductId) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $excludeProductId));
        }

        return \array_slice($ids, 0, $limit);
    }
}

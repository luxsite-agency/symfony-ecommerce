<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Versioned namespace кешу каталогу (ADR D3): ключі результатів CatalogQuery містять поточну версію; інвалідація —
 * не видалення ключів, а bump() версії (після імпорту, регенерації товарів, повної перебудови індексу).
 * Точкові оновлення залишку версію не міняють — їх покриває короткий TTL результатів (CatalogQuery::TTL).
 */
final class CatalogCacheVersion
{
    private const string KEY = 'catalog.filter.version';
    private ?string $current = null;

    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $pool)
    {
    }

    public function current(): string
    {
        if (null !== $this->current) {
            return $this->current;
        }
        $item = $this->pool->getItem(self::KEY);
        $value = $item->isHit() ? $item->get() : null;
        if (!\is_string($value) || '' === $value) {
            $value = self::fresh();
            $this->pool->save($item->set($value));
        }

        return $this->current = $value;
    }

    public function bump(): string
    {
        $value = self::fresh();
        $this->pool->save($this->pool->getItem(self::KEY)->set($value));

        return $this->current = $value;
    }

    /** Мітка часу з мікросекундами: монотонно зростає між bump()-ами різних процесів без лічильника в БД. */
    private static function fresh(): string
    {
        return \sprintf('%d', (int) (microtime(true) * 1_000_000));
    }
}

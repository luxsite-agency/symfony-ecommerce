<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Розумний пошук розділу адмінки замість типового LIKE EasyAdmin по setSearchFields (SmartSearchRepository): свої поля,
 * нормалізація (телефон, номер замовлення), індекси. Розділи без власного пошукача — типовий пошук EasyAdmin (усі слова, AND).
 */
#[AutoconfigureTag(self::TAG)]
interface AdminSearcher
{
    public const string TAG = 'app.admin_searcher';

    public function supports(string $entityFqcn): bool;

    /**
     * Умови на корінь 'entity': кожне слово має знайтись хоч в одному полі.
     *
     * @param list<string> $terms AdminSearchQuery::terms()
     */
    public function apply(QueryBuilder $qb, array $terms): void;

    /** Точний збіг (номер замовлення, SKU, штрихкод) з одним записом — його id: адмінка одразу відкриває картку. */
    public function exactId(string $query): ?int;

    /** Ключ підказки в полі пошуку (домен admin): які поля шукаються. */
    public function hintKey(): string;
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

/**
 * Що зробив пошук на сторінці списку (атрибут запиту ATTRIBUTE, читає crud/index.html.twig): запит, за яким показано
 * результати (інша розкладка — suggestion), і слова для підсвітки в таблиці.
 */
final readonly class AdminSearchState
{
    public const string ATTRIBUTE = '_admin_search';

    /** @param list<string> $terms */
    public function __construct(
        public string $query,
        public ?string $suggestion,
        public array $terms,
    ) {
    }

    /** Запит, за яким реально шукали: виправлена розкладка або те, що ввели. */
    public function effective(): string
    {
        return $this->suggestion ?? $this->query;
    }
}

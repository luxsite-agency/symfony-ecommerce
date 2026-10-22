<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** admin_search_hint(entityFqcn) — ключ підказки в полі пошуку розділу: що саме шукається (crud/index.html.twig). */
final class AdminSearchExtension extends AbstractExtension
{
    public function __construct(private readonly SmartSearchRepository $search)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_search_hint', $this->hint(...))];
    }

    public function hint(?string $entityFqcn): string
    {
        $searcher = null === $entityFqcn ? null : $this->search->searcher($entityFqcn);
        if (null !== $searcher) {
            return $searcher->hintKey();
        }
        $short = null === $entityFqcn ? '' : strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr((string) strrchr('\\'.$entityFqcn, '\\'), 1)));

        return \in_array($short, self::HINTS, true) ? 'search.hint.'.$short : 'search.hint.default';
    }

    /** Розділи з типовим пошуком EasyAdmin, для яких є своя підказка (поля — setSearchFields їхніх CRUD). */
    private const array HINTS = ['category', 'brand', 'attribute', 'media', 'promotion', 'product_review', 'wholesale_application', 'company',
        'customer_group', 'page', 'post', 'post_category', 'redirect', 'not_found_entry', 'admin_audit_entry', 'changeset', 'admin_api_token',
        'ai_usage', 'user', 'setting', 'solution', 'solution_group'];
}

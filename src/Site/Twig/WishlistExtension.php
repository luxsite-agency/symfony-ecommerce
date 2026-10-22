<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Customer\Wishlist\Wishlist;
use App\Site\Customer\CustomerContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * wishlist_has(productId) для кнопки «в обране» на картках: один запит на сторінку для залогіненого покупця;
 * гість (без сесії) — завжди false, security не торкається (CustomerContext), картка лишається без cookie.
 */
final class WishlistExtension extends AbstractExtension
{
    /** @var array<int, true>|null */
    private ?array $ids = null;

    public function __construct(private readonly CustomerContext $customers, private readonly Wishlist $wishlist)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('wishlist_has', $this->has(...)),
        ];
    }

    public function has(int $productId): bool
    {
        if (null === $this->ids) {
            $customer = $this->customers->customer();
            $this->ids = null === $customer ? [] : array_fill_keys($this->wishlist->productIds($customer), true);
        }

        return isset($this->ids[$productId]);
    }
}

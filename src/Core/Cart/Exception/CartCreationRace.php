<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart\Exception;

use RuntimeException;
use Throwable;

/**
 * Кошик покупця створив паралельний запит (unique customer_id, рев'ю #58 п. 1): два швидкі «У кошик» без кошика.
 * EntityManager уже скинуто; контролер веде на кошик — рядок уже поклав перший запит або покладе наступна спроба.
 */
final class CartCreationRace extends RuntimeException
{
    public function __construct(public readonly int $customerId, ?Throwable $previous = null)
    {
        parent::__construct(\sprintf('Кошик покупця #%d щойно створив інший запит', $customerId), 0, $previous);
    }
}

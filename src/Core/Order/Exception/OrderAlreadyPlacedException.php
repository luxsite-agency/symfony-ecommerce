<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Exception;

use RuntimeException;
use Throwable;

/** Гонка ідемпотентності (подвійний сабміт): замовлення з цим ключем уже створив паралельний запит — редирект на його «дякуємо». */
final class OrderAlreadyPlacedException extends RuntimeException
{
    public function __construct(public readonly string $successToken, ?Throwable $previous = null)
    {
        parent::__construct('Замовлення з цим ключем уже оформлено', 0, $previous);
    }
}

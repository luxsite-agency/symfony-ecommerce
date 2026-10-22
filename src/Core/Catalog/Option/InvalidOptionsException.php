<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Option;

use InvalidArgumentException;

/** InvalidArgumentException — як і решта помилок вводу кошика (CartController ловить). Вибір опцій не проходить правила групи (обов'язковість, одиночний вибір, межі й крок числа, недоступний компонент). */
final class InvalidOptionsException extends InvalidArgumentException
{
    /** @param array<string, string> $errors код групи → повідомлення */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}

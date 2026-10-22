<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use InvalidArgumentException;

/** Телефон покупця → +380XXXXXXXXX (ADR D6: нормалізований телефон); інші країни — цифри з плюсом. */
final class PhoneNormalizer
{
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (10 === \strlen($digits) && str_starts_with($digits, '0')) {
            $digits = '38'.$digits;
        } elseif (9 === \strlen($digits)) {
            $digits = '380'.$digits;
        }
        if (\strlen($digits) < 10 || \strlen($digits) > 15) {
            throw new InvalidArgumentException('Вкажіть номер телефону у форматі +380XXXXXXXXX');
        }

        return '+'.$digits;
    }
}

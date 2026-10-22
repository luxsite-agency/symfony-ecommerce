<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Text;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;

/** Машинний код (атрибут, група опцій, група клієнтів, зовнішня система): snake_case латиницею, до 64 символів. */
final class MachineCode
{
    public static function assertValid(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code) || \strlen($code) > 64) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не машинний код (snake_case, до 64): "%s"', $code), 'error.machine_code.invalid', ['value' => $code]);
        }

        return $code;
    }
}

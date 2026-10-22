<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use InvalidArgumentException;

/**
 * Код компанії для заявки й компанії: ЄДРПОУ (8 цифр, юридична особа) або РНОКПП (10 цифр, ФОП). РНОКПП ФОП є
 * персональними даними (консиліум 07.10, П2): у журнали не пишеться, назовні не передається.
 */
final class TaxId
{
    public const string PATTERN = '/^(\d{8}|\d{10})$/';
    /** Те саме для поля форми: цифри можуть бути з пробілами чи дефісами, їх прибирає normalize(). */
    public const string INPUT_PATTERN = '/^\s*(?:\d[\s\-]*){8}\s*$|^\s*(?:\d[\s\-]*){10}\s*$/';

    public static function normalize(string $value): string
    {
        $digits = preg_replace('/[\s\-]+/', '', trim($value)) ?? '';
        if (1 !== preg_match(self::PATTERN, $digits)) {
            throw new TranslatableInvalidArgumentException('ЄДРПОУ має 8 цифр, РНОКПП 10 цифр', 'error.company.tax_id_format');
        }

        return $digits;
    }

    public static function isValid(string $value): bool
    {
        try {
            self::normalize($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Юридична особа (ЄДРПОУ) чи ФОП (РНОКПП) для підписів у адмінці. */
    public static function kind(string $taxId): string
    {
        return 10 === \strlen($taxId) ? 'РНОКПП' : 'ЄДРПОУ';
    }
}

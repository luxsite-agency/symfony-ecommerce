<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/** Текст винятку для людини в адмінці: перекладений, якщо виняток це вміє (TranslatableException), інакше getMessage(). */
final class ExceptionMessage
{
    public static function trans(Throwable $e, TranslatorInterface $translator, ?string $locale = null): string
    {
        return $e instanceof TranslatableException ? $e->trans($translator, $locale) : $e->getMessage();
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Text;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;

/** Слаг для URL: латиниця в нижньому регістрі, цифри, дефіси між ними; до 190 символів (індекс utf8mb4). */
final class Slug
{
    public static function assertValid(string $slug): string
    {
        $slug = trim($slug);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || \strlen($slug) > 190) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не слаг: "%s"', $slug), 'error.slug.invalid', ['value' => $slug]);
        }

        return $slug;
    }
}

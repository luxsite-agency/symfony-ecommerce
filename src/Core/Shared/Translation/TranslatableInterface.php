<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

/**
 * Сутність із перекладами за локаллю (реалізація — TranslatableTrait). Потрібен формам адмінки,
 * які редагують усі мови магазину однією формою.
 */
interface TranslatableInterface
{
    public function translate(string $locale): AbstractTranslation;

    public function getTranslation(string $locale): ?AbstractTranslation;

    public function removeTranslation(string $locale): void;

    /** @return list<string> */
    public function getTranslatedLocales(): array;
}

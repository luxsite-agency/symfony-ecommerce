<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use Symfony\Contracts\Translation\TranslatableInterface as SymfonyTranslatable;

/**
 * Доменний виняток, текст якого адмінка показує мовою інтерфейсу: ключ error.* у домені admin (ICU) і параметри.
 * getMessage() лишається українським — для логів, API, CLI і тестів; переклад — лише через trans().
 */
interface TranslatableException extends SymfonyTranslatable
{
    public const string DOMAIN = 'admin';

    /** Ключ перекладу; '' — ключа нема, trans() віддає getMessage(). */
    public function getMessageKey(): string;

    /** @return array<string, scalar|null> */
    public function getMessageParameters(): array;
}

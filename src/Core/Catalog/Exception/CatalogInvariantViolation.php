<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Exception;

use App\Core\Shared\Translation\TranslatableDomainException;

/**
 * Спроба привести каталог у недопустимий стан (цикл у дереві, товар без варіанта, чужа валюта тощо).
 * Ключ error.* — для форми адмінки (TranslatableException), getMessage() — український.
 */
final class CatalogInvariantViolation extends TranslatableDomainException
{
}

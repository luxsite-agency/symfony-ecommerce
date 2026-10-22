<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Exception;

use InvalidArgumentException;

/** Рядок фіду не проходить перевірку DTO: у звіт, імпорт триває. */
final class InvalidImportRowException extends InvalidArgumentException
{
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Exception;

use RuntimeException;

/**
 * Фід відхилено з міркувань безпеки (консиліум 10.10 п. 1, 3): DOCTYPE/ENTITY у XML (XXE), адреса в приватній мережі,
 * перевищений ліміт розміру, схема поза http(s). Імпорт не починається; повідомлення — оператору в консоль.
 */
final class UnsafeFeedException extends RuntimeException
{
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Exception;

use RuntimeException;

/** Паралельний імпорт того ж джерела: lock зайнятий або є незавершений ImportRun. */
final class ImportAlreadyRunningException extends RuntimeException
{
}

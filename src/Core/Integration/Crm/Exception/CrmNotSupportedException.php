<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm\Exception;

use RuntimeException;

/** Конектор не вміє операцію (див. capabilities()). */
final class CrmNotSupportedException extends RuntimeException
{
}

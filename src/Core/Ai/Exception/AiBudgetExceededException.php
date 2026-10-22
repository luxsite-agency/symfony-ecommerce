<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Exception;

/** Місячний ліміт витрат (ai.monthly_budget_usd) вичерпано — до кінця місяця не викликаємо. */
final class AiBudgetExceededException extends AiException
{
}

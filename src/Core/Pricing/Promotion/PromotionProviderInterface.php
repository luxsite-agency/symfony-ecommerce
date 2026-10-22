<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing\Promotion;

use App\Core\Pricing\Promotion\Entity\Promotion;
use DateTimeImmutable;

/** Джерело чинних акцій на момент часу; Doctrine у застосунку, масив у юніт-тестах. */
interface PromotionProviderInterface
{
    /** @return list<Promotion> активні й у датах, у будь-якому порядку */
    public function running(DateTimeImmutable $at): array;
}

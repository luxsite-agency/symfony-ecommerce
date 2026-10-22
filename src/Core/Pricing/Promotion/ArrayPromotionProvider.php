<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing\Promotion;

use App\Core\Pricing\Promotion\Entity\Promotion;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Акції з масиву — для юніт-тестів калькулятора й резолвера. */
#[Exclude]
final class ArrayPromotionProvider implements PromotionProviderInterface
{
    /** @param list<Promotion> $promotions */
    public function __construct(private readonly array $promotions = [])
    {
    }

    public function running(DateTimeImmutable $at): array
    {
        return array_values(array_filter($this->promotions, static fn (Promotion $p): bool => $p->isRunning($at)));
    }
}

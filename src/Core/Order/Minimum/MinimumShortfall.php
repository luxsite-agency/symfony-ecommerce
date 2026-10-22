<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Minimum;

use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Money\Money;

/** Чого бракує до мінімуму групи (E13 PR-7): сума і/або кількість; null у полі — цей мінімум виконано або не задано. */
final readonly class MinimumShortfall
{
    public function __construct(
        public ?Money $minAmount,
        public ?Money $missingAmount,
        public ?string $minQuantity,
        public ?string $missingQuantity,
    ) {
    }

    /** Повідомлення для PlaceOrder (помилка форми; сторінки сайту беруть переклад з тих самих полів). */
    public function message(): string
    {
        $parts = [];
        if (null !== $this->minAmount && null !== $this->missingAmount) {
            $parts[] = \sprintf('сума від %s (бракує %s)', DisplayFormat::money($this->minAmount, 'uk'), DisplayFormat::money($this->missingAmount, 'uk'));
        }
        if (null !== $this->minQuantity && null !== $this->missingQuantity) {
            $parts[] = \sprintf('кількість від %s одиниць (бракує %s)', DisplayFormat::quantity($this->minQuantity, 'uk'), DisplayFormat::quantity($this->missingQuantity, 'uk'));
        }

        return 'Мінімальне замовлення для вашої групи цін: '.implode(', ', $parts);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Exception;

use App\Core\Shared\Translation\TranslatableException;
use App\Core\Shared\Translation\TranslatableExceptionTrait;
use Symfony\Component\Workflow\Exception\LogicException;

/** Перехід стану замовлення недоступний: для логів — з кодами, в адмінці — фразою мовою адмінки (error.order.transition_unavailable). */
final class TransitionUnavailable extends LogicException implements TranslatableException
{
    use TranslatableExceptionTrait;

    public function __construct(string $transition, string $status, string $number)
    {
        parent::__construct(\sprintf('Перехід «%s» недоступний зі стану «%s» (замовлення %s)', $transition, $status, $number));
        $this->setMessageKey('error.order.transition_unavailable', ['number' => $number]);
    }
}

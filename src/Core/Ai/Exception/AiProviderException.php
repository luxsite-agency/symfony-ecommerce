<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Exception;

/** 5xx, мережа, невалідна відповідь — повтор має шанс. */
class AiProviderException extends AiException
{
    public function isRetryable(): bool
    {
        return true;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AiUsageStatus: string implements TranslatableInterface
{
    case Queued = 'queued';
    case Ok = 'ok';
    case Error = 'error';
    case Rejected = 'rejected';

    /** Підпис у журналі AI мовою адмінки (enum.ai_usage_status). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.ai_usage_status.'.$this->value, [], 'admin', $locale);
    }
}

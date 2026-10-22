<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** running → finalizing (один переможець: останній чанк або CLI після staging) → completed | failed. */
enum ImportRunStatus: string
{
    case Running = 'running';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return self::Running === $this || self::Finalizing === $this;
    }
}

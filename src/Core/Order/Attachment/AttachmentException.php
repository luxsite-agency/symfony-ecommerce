<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Attachment;

use RuntimeException;

/** key — ключ перекладу помилки (site.lead.error.<key>): too_large | bad_type | upload_failed. */
final class AttachmentException extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}

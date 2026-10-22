<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Поля форми /request (site/partials/_lead_form.html.twig) до перевірки. Рядки — як прийшли, нормалізує LeadRequestService. */
final readonly class LeadRequest
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $email = '',
        public string $company = '',
        public string $comment = '',
        public string $context = '',
        public string $choice = '',
        public string $sourceUrl = '',
        public ?UploadedFile $attachment = null,
        public string $locale = 'uk',
        public ?string $ip = null,
    ) {
    }
}

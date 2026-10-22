<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Dto;

/** Відповідь моделі: текст і використання. cost — кредити OpenRouter (≈ USD) з usage.cost; null, якщо провайдер не дав. */
final readonly class ChatResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public ?float $cost,
        public ?string $providerId = null,
        public ?string $finishReason = null,
    ) {
    }
}

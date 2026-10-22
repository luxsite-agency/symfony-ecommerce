<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Dto;

/**
 * Запит до чат-моделі (OpenAI-сумісний): системна інструкція + текст користувача. jsonSchema — структурована відповідь
 * (response_format json_schema), коли треба розбирати результат, а не показувати як є.
 *
 * @phpstan-type JsonSchema array<string, mixed>
 */
final readonly class ChatRequest
{
    /** @param JsonSchema|null $jsonSchema */
    public function __construct(
        public string $model,
        public string $system,
        public string $user,
        public int $maxOutputTokens,
        public ?array $jsonSchema = null,
        public ?float $temperature = null,
    ) {
    }

    /** @return array<string, mixed> тіло /chat/completions */
    public function toPayload(): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->system],
                ['role' => 'user', 'content' => $this->user],
            ],
            'max_tokens' => $this->maxOutputTokens,
            // OpenRouter повертає usage.cost (кредити ≈ USD) лише на явний запит — це джерело журналу витрат
            'usage' => ['include' => true],
        ];
        if (null !== $this->temperature) {
            $payload['temperature'] = $this->temperature;
        }
        if (null !== $this->jsonSchema) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'strict' => true, 'schema' => $this->jsonSchema]];
        }

        return $payload;
    }
}

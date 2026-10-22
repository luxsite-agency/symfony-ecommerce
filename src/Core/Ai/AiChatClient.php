<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Ai\Dto\ChatRequest;
use App\Core\Ai\Dto\ChatResponse;
use App\Core\Ai\Dto\KeyInfo;

/**
 * Клієнт чат-моделі (OpenAI-сумісний). У v1 реалізація одна — OpenRouter; інший провайдер = інший base URL і заголовок.
 * Ретраїв тут нема: 429/5xx — винятки, повторює Messenger.
 */
interface AiChatClient
{
    /** @throws Exception\AiException */
    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Перевірка ключа без витрати токенів.
     *
     * @throws Exception\AiException
     */
    public function keyInfo(): KeyInfo;
}

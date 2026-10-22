<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Реалізація TranslatableException для класу-винятку: ключ і параметри задає конструктор. */
trait TranslatableExceptionTrait
{
    private string $messageKey = '';

    /** @var array<string, scalar|null> */
    private array $messageParameters = [];

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /** @return array<string, scalar|null> */
    public function getMessageParameters(): array
    {
        return $this->messageParameters;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        if ('' === $this->messageKey) {
            return $this->getMessage();
        }
        $text = $translator->trans($this->messageKey, $this->messageParameters, TranslatableException::DOMAIN, $locale);

        // ключа нема в перекладах — краще український текст, ніж сирий ключ
        return $text === $this->messageKey ? $this->getMessage() : $text;
    }

    /** @param array<string, scalar|null> $parameters */
    private function setMessageKey(string $key, array $parameters): void
    {
        $this->messageKey = $key;
        $this->messageParameters = $parameters;
    }
}

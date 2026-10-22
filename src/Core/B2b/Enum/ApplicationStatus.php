<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Стан заявки «Стати оптовим клієнтом» (E13, PR-5). Нова чекає менеджера; схвалена й відхилена незмінні. */
enum ApplicationStatus: string implements TranslatableInterface
{
    case New = 'new';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Ключ підпису в домені admin: enum.application_status.<код>. */
    public function transKey(): string
    {
        return 'enum.application_status.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }

    public function isFinal(): bool
    {
        return self::New !== $this;
    }
}

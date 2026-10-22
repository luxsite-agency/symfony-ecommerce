<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Стан пакета змін (E15 PR-3): запропоновано → застосовано | відхилено | прострочено (ліниво за expires_at). */
enum ChangesetStatus: string implements TranslatableInterface
{
    case Proposed = 'proposed';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /** Назва в адмінці мовою інтерфейсу (admin+intl-icu, enum.changeset_status.*) */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.changeset_status.'.$this->value, [], 'admin', $locale);
    }

    /** Українська назва без перекладача (CLI, тексти винятків API); адмінка — trans() */
    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Запропоновано',
            self::Applied => 'Застосовано',
            self::Rejected => 'Відхилено',
            self::Expired => 'Прострочено',
        };
    }
}

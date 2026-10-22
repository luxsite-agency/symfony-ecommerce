<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Звідки прийшла зміна (E15 PR-1): адмінка, пакет змін через AI-API, імпорт, консоль. */
enum AuditSource: string implements TranslatableInterface
{
    case AdminUi = 'admin_ui';
    case AiApi = 'ai_api';
    case Import = 'import';
    case Cli = 'cli';

    /** Назва в адмінці мовою інтерфейсу (admin+intl-icu, enum.audit_source.*) */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.audit_source.'.$this->value, [], 'admin', $locale);
    }

    /** Українська назва без перекладача (CLI, тексти винятків API); адмінка — trans() */
    public function label(): string
    {
        return match ($this) {
            self::AdminUi => 'Адмінка',
            self::AiApi => 'AI (API)',
            self::Import => 'Імпорт',
            self::Cli => 'Консоль',
        };
    }
}

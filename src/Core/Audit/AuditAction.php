<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AuditAction: string implements TranslatableInterface
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    /** Пакет змін застосовано (E15 PR-3): рядок на пакет з прапорцями ризику й фактом підтвердження; зміни полів — окремими рядками з changeset_id */
    case Apply = 'apply';
    case Reject = 'reject';

    /** Назва в адмінці мовою інтерфейсу (admin+intl-icu, enum.audit_action.*) */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.audit_action.'.$this->value, [], 'admin', $locale);
    }

    /** Українська назва без перекладача (CLI, тексти винятків API); адмінка — trans() */
    public function label(): string
    {
        return match ($this) {
            self::Create => 'Створено',
            self::Update => 'Змінено',
            self::Delete => 'Видалено',
            self::Apply => 'Застосовано',
            self::Reject => 'Відхилено',
        };
    }
}

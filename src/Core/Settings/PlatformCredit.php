<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Settings;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Рядок платформи у футері сайту (налаштування site.platform_credit, рішення власника 19.10.2026): повний (платформа,
 * Apache 2.0, розробники, посилання «Ліцензія»), коротко («Ecommerce LuxSite», однаково всіма мовами) або не показувати.
 * Під Apache 2.0 показ на сайті не обов'язковий: NOTICE стосується поширення коду. /license лишається за адресою.
 */
enum PlatformCredit: string implements TranslatableInterface
{
    case Full = 'full';
    case Short = 'short';
    case None = 'none';

    /** Ключ підпису в домені admin: enum.platform_credit.<код>. */
    public function transKey(): string
    {
        return 'enum.platform_credit.'.$this->value;
    }

    /** Підпис мовою адмінки (перемикачі й список «Налаштувань сайту»). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }

    /** Порожнє чи невідоме значення — «Коротко», типове для нових і наявних магазинів. */
    public static function fromSetting(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::Short;
    }

    /** @return array<string, string> ключ підпису (домен admin) → значення, для поля вибору в адмінці */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->transKey()] = $case->value;
        }

        return $choices;
    }
}

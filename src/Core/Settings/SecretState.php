<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Settings;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Стан секретного налаштування (E15 BYOK): лише Ready дає відкритий текст. Unreadable — конверт у БД є, але
 * SETTINGS_SECRET_KEY інший чи втрачений (бекап на новому сервері, перегенерований ключ) або дані пошкоджені:
 * секрет вважається відсутнім, в адмінці — «введіть знову», у security_audit — запис. Не 500.
 */
enum SecretState: string implements TranslatableInterface
{
    case Missing = 'missing';
    case NoKey = 'no_key';
    case Unreadable = 'unreadable';
    case Ready = 'ready';

    public function isReady(): bool
    {
        return self::Ready === $this;
    }

    /** Коротко для бейджа в списку налаштувань, мовою адмінки (enum.secret_state). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.secret_state.'.$this->value, [], 'admin', $locale);
    }

    /** Пояснення для адміністратора: що сталося і що робити (enum.secret_state.hint). */
    public function hint(): ?TranslatableMessage
    {
        return match ($this) {
            self::Missing, self::Ready => null,
            self::NoKey, self::Unreadable => new TranslatableMessage('enum.secret_state.hint.'.$this->value, [], 'admin'),
        };
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi;

use App\Core\Security\Enum\UserRole;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Скоупи персональних API-токенів (E15 PR-2, consilium critic.md §1). Скоуп дає доступ лише разом з роллю власника
 * токена (ScopeVoter): токен не може більше за людину. Нема orders/stock/promotions/media/customers — у v1 не буде.
 * theme:write (20.10.2026) — живі шаблони й CSS сайту (ThemeService), не пакети: застосовується одразу, як у service-engine.
 */
enum ApiScope: string implements TranslatableInterface
{
    case CatalogRead = 'catalog:read';
    case ContentWrite = 'content:write';
    case PricesWrite = 'prices:write';
    /** Живий дизайн (рішення власника 20.10.2026, модель service-engine): шаблони site/** і theme.css у var/share/theme, одразу на сайті. */
    case ThemeWrite = 'theme:write';

    /** Українською — для CLI й документації; в адмінці — trans() мовою адмінки. */
    public function label(): string
    {
        return match ($this) {
            self::CatalogRead => 'Читання каталогу й контенту',
            self::ContentWrite => 'Пакети змін контенту (тексти, SEO, slug, категорії, активність)',
            self::PricesWrite => 'Пакети змін цін (ціни варіантів, пороги)',
            self::ThemeWrite => 'Живий дизайн: шаблони site/** і theme.css з перевіркою рендера й історією версій',
        };
    }

    /** Підпис скоупу в адмінці (форма токена, фільтр «Дозвіл»): enum.api_scope.<значення>. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->translationKey(), [], 'admin', $locale);
    }

    public function translationKey(): string
    {
        return 'enum.api_scope.'.$this->value;
    }

    /** Роль, яка дає те саме в адмінці; без неї скоуп у токені нічого не дає. */
    public function requiredRole(): UserRole
    {
        return UserRole::Manager;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $scope = \is_string($value) ? self::tryFrom($value) : null;
            if (null === $scope) {
                throw new InvalidArgumentException(\sprintf('Невідомий скоуп «%s»', \is_scalar($value) ? (string) $value : get_debug_type($value)));
            }
            if (!\in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }

        return $out;
    }

    /** @return array<string, string> ключ перекладу назви (домен admin) → значення (для фільтрів EasyAdmin) */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->translationKey()] = $case->value;
        }

        return $choices;
    }
}

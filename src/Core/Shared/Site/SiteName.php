<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Site;

use JsonSerializable;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Назва магазину з параметра app.site_name: рядок (для всіх мов) або мапа {locale: назва} з фолбеком на мову за
 * замовчуванням, потім на першу непорожню. Twig-глобал site_name — цей сервіс: друкується (Stringable) і
 * серіалізується в JSON-LD (JsonSerializable) мовою поточного запиту; листи беруть назву мовою замовлення (forLocale).
 */
final class SiteName implements Stringable, JsonSerializable
{
    /**
     * @param string|array<string, string> $name
     */
    public function __construct(
        #[Autowire('%app.site_name%')] private readonly string|array $name,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        private readonly RequestStack $requests,
    ) {
    }

    /** Назва мовою $locale (null — мова поточного запиту, без запиту — мова за замовчуванням). */
    public function forLocale(?string $locale = null): string
    {
        $locale ??= $this->requests->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;
        if (\is_string($this->name)) {
            return $this->name;
        }
        foreach ([$locale, $this->defaultLocale] as $candidate) {
            if (isset($this->name[$candidate]) && '' !== trim((string) $this->name[$candidate])) {
                return trim((string) $this->name[$candidate]);
            }
        }
        foreach ($this->name as $value) {
            if ('' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** Назва мовою магазину за замовчуванням (службові листи, адмінка). */
    public function forDefaultLocale(): string
    {
        return $this->forLocale($this->defaultLocale);
    }

    public function __toString(): string
    {
        return $this->forLocale();
    }

    public function jsonSerialize(): string
    {
        return $this->forLocale();
    }
}

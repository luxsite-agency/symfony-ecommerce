<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\License;

use App\Core\Shared\Site\SiteName;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ліцензія платформи для сторінки /license сайту (site_license) і сторінки «Ліцензія» в адмінці. Код рушія — Apache 2.0 (LICENSE,
 * NOTICE), це однаково для всіх сайтів; з параметра app.license (пак — config/parameters.yaml) — лише реквізити сайту: власник
 * (licensee: рядок або мапа за мовою; порожньо → назва магазину app.site_name) і договір з LuxSite на обслуговування: тип
 * (subscription | perpetual; порожньо → «не вказано»), номер і дата. Домени — site_host/admin_host розгортання.
 * Текст ліцензії — templates/license/text.<мова>.html.twig лише мовами TEXT_LOCALES; решта мов сайту отримує TEXT_FALLBACK
 * (окремого ru-тексту нема — рішення координатора 11.10.2026). Невідомий ключ або значення — виняток, як у SiteTheme: помилка
 * конфігу паку має бути видимою, а не тихо показувати «не вказано»; те саме наперед перевіряє bin/store validate.
 */
final class PlatformLicense
{
    public const string TYPE_SUBSCRIPTION = 'subscription';
    public const string TYPE_PERPETUAL = 'perpetual';
    public const array TYPES = [self::TYPE_SUBSCRIPTION, self::TYPE_PERPETUAL];
    /** Мови, якими є текст ліцензії (templates/license/text.<мова>.html.twig). */
    public const array TEXT_LOCALES = ['uk', 'en'];
    public const string TEXT_FALLBACK = 'en';
    private const array KEYS = ['licensee', 'type', 'contract', 'since'];

    /** @var string|array<string, string>|null */
    private readonly string|array|null $licensee;
    private readonly ?string $type;
    private readonly ?string $contract;
    private readonly ?DateTimeImmutable $since;
    private readonly bool $configured;

    /** @param array<string, mixed> $config параметр app.license */
    public function __construct(
        #[Autowire('%app.license%')] array $config,
        private readonly SiteName $siteName,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
        $unknown = array_diff(array_keys($config), self::KEYS);
        if ([] !== $unknown) {
            throw new InvalidArgumentException('app.license: невідомі ключі '.implode(', ', $unknown).' (дозволено: '.implode(', ', self::KEYS).')');
        }
        $this->configured = [] !== array_filter($config, static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v);
        $this->licensee = self::licenseeOf($config['licensee'] ?? null);
        $this->type = self::typeOf($config['type'] ?? null);
        $this->contract = self::stringOf($config['contract'] ?? null, 'contract');
        $this->since = self::sinceOf($config['since'] ?? null);
    }

    /** app.license задано в розгортанні (хоч один ключ); інакше сторінка показує дефолти: власник = назва магазину, договір «не вказано». */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** Власник сайту мовою $locale (мапа — з фолбеком на мову за замовчуванням, потім першу непорожню); без app.license.licensee — назва магазину. */
    public function licensee(?string $locale = null): string
    {
        $locale ??= $this->defaultLocale;
        if (\is_string($this->licensee)) {
            return $this->licensee;
        }
        if (\is_array($this->licensee)) {
            foreach ([$locale, $this->defaultLocale] as $candidate) {
                if (isset($this->licensee[$candidate]) && '' !== trim($this->licensee[$candidate])) {
                    return trim($this->licensee[$candidate]);
                }
            }
            foreach ($this->licensee as $value) {
                if ('' !== trim($value)) {
                    return trim($value);
                }
            }
        }

        return $this->siteName->forLocale($locale);
    }

    /** Тип договору з LuxSite: subscription | perpetual | null («не вказано»). */
    public function type(): ?string
    {
        return $this->type;
    }

    public function contract(): ?string
    {
        return $this->contract;
    }

    public function since(): ?DateTimeImmutable
    {
        return $this->since;
    }

    public function siteHost(): string
    {
        return $this->siteHost;
    }

    public function adminHost(): string
    {
        return $this->adminHost;
    }

    /** Мова тексту ліцензії для мови сайту: та сама, якщо текст є, інакше TEXT_FALLBACK (ru → en). */
    public function textLocale(string $locale): string
    {
        return \in_array($locale, self::TEXT_LOCALES, true) ? $locale : self::TEXT_FALLBACK;
    }

    /** Шаблон тексту ліцензії для мови сайту (templates/license/). */
    public function textTemplate(string $locale): string
    {
        return 'license/text.'.$this->textLocale($locale).'.html.twig';
    }

    /** @return string|array<string, string>|null */
    private static function licenseeOf(mixed $value): string|array|null
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }
        if (\is_string($value)) {
            return trim($value);
        }
        if (!\is_array($value)) {
            throw new InvalidArgumentException('app.license.licensee: рядок або мапа {мова: назва}');
        }
        $map = [];
        foreach ($value as $locale => $name) {
            if (!\is_string($locale) || !\is_string($name)) {
                throw new InvalidArgumentException('app.license.licensee: мапа {мова: назва} з рядковими значеннями');
            }
            $map[$locale] = $name;
        }

        return $map;
    }

    private static function typeOf(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || !\in_array($value, self::TYPES, true)) {
            throw new InvalidArgumentException('app.license.type: '.implode(' | ', self::TYPES).' або порожньо');
        }

        return $value;
    }

    private static function stringOf(mixed $value, string $key): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw new InvalidArgumentException('app.license.'.$key.': рядок');
        }

        return trim((string) $value);
    }

    private static function sinceOf(mixed $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        // YAML без лапок парсить 2026-01-01 як дату — у Symfony-параметрах це рядок, але страхуємось
        $string = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
        $date = \is_string($string) ? DateTimeImmutable::createFromFormat('!Y-m-d', $string) : false;
        if (false === $date || $date->format('Y-m-d') !== $string) {
            throw new InvalidArgumentException("app.license.since: дата у форматі 'YYYY-MM-DD' (у лапках)");
        }

        return $date;
    }
}

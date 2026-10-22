<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Site\SiteName;
use NumberFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Мета товару за замовчуванням (seo-tech-notes п. 3, 8), коли в перекладі порожні meta_title / meta_description /
 * описи: шаблони з параметра магазину app.seo.product ({name}, {price}; окремі шаблони для «ціни за запитом»), рядок або
 * мапа за мовою. «Ціна від» (PriceMode::From) — свої шаблони title_from / description_from («від {price} грн»): точна
 * «ціна X грн» там неправда; без них — лише {name} і без опису-шаблону. Назву магазину в title дописує base.html.twig (« | site_name»), тому в шаблоні її нема; якщо title з
 * цим суфіксом довший за app.seo.title_max — лишається «{name}». Опис обрізається до 155 знаків по реченню.
 *
 * @phpstan-type Templates array{title?: string|array<string, string>, title_on_request?: string|array<string, string>, title_from?: string|array<string, string>, description?: string|array<string, string>, description_on_request?: string|array<string, string>, description_from?: string|array<string, string>}
 */
final class ProductSeo
{
    public const int DESCRIPTION_MAX = 155;

    /** @param array{title_max?: int, brand?: string|array<string, string>|null, product?: Templates} $seo */
    public function __construct(
        #[Autowire('%app.seo%')] private readonly array $seo,
        private readonly SiteName $siteName,
    ) {
    }

    /** @return array{title: string, description: string} title — без суфікса магазину */
    public function meta(Product $product, ProductTranslation $translation, ?Money $price, string $locale): array
    {
        $mode = match (true) {
            PriceMode::OnRequest === $product->getPriceMode() || null === $price => '_on_request',
            PriceMode::From === $product->getPriceMode() => '_from',
            default => '',
        };
        $vars = ['{name}' => $translation->getName(), '{price}' => null === $price ? '' : self::price($price, $locale)];

        $title = trim((string) $translation->getMetaTitle());
        if ('' === $title) {
            $title = strtr($this->template('title'.$mode, $locale) ?? '{name}', $vars);
            $suffix = ' | '.$this->siteName->forLocale($locale);
            if (mb_strlen($title.$suffix) > (int) ($this->seo['title_max'] ?? 60)) {
                $title = $translation->getName();
            }
        }

        $description = trim((string) ($translation->getMetaDescription() ?: $translation->getShortDescription() ?: strip_tags((string) $translation->getDescription())));
        if ('' === $description) {
            $description = strtr($this->template('description'.$mode, $locale) ?? '', $vars);
        }

        return ['title' => $title, 'description' => self::cut(html_entity_decode($description, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))];
    }

    /** Бренд для schema Product: бренд товару, інакше app.seo.brand (рядок або мапа за мовою), інакше null. */
    public function brand(Product $product, string $locale): ?string
    {
        $brand = $product->getBrand()?->getName();
        if (null !== $brand && '' !== $brand) {
            return $brand;
        }

        return self::localized($this->seo['brand'] ?? null, $locale);
    }

    private function template(string $key, string $locale): ?string
    {
        return self::localized($this->seo['product'][$key] ?? null, $locale);
    }

    /** @param string|array<string, string>|null $value */
    private static function localized(string|array|null $value, string $locale): ?string
    {
        if (\is_array($value)) {
            $value = $value[$locale] ?? null;
        }
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }

    /** 215 / 1 250,50 — число мовою сторінки, без валюти (її пише шаблон: «{price} грн»). */
    private static function price(Money $price, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0 === $price->amount % 100 ? 0 : 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format($price->amount / 100);
    }

    /** До DESCRIPTION_MAX знаків: цілими реченнями; якщо перше довше — по слову з «…». */
    private static function cut(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }
        $result = '';
        foreach (preg_split('/(?<=[.!?…])\s+/u', $text) ?: [] as $sentence) {
            if (mb_strlen(trim($result.' '.$sentence)) > self::DESCRIPTION_MAX) {
                break;
            }
            $result = trim($result.' '.$sentence);
        }
        if ('' !== $result) {
            return $result;
        }
        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX - 1);

        // rtrim() байтовий: «—» у списку символів зрізав би хвости UTF-8 літер «р», «є» (D1 80, D1 94) → невалідний рядок
        return (string) preg_replace('/[\s,;:—-]+$/u', '', mb_substr($cut, 0, (int) mb_strrpos($cut, ' ') ?: mb_strlen($cut))).'…';
    }
}

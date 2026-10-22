<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed;

/**
 * Канал товарного фіду: той самий RSS 2.0 з g: і ті самі item (варіант = item, g:id = SKU = item_id у dataLayer), профіль
 * каналу задає лише відмінності специфікацій.
 *
 * Google Merchant Center (Shopping, Performance Max, динамічний ремаркетинг Google Ads) — support.google.com/merchants/answer/7052112.
 * Meta Commerce Manager (каталог для Advantage+ catalog ads і динамічного ремаркетингу) — developers.facebook.com/docs/commerce-platform/catalog/fields,
 * facebook.com/business/help/120325381656392: brand обов'язковий (≤ 100), title ≤ 200, description ≤ 9999, до 20 additional_image_link,
 * availability лише «in stock» / «out of stock» (з пробілом), полів Google про упаковку/одиниці й дату наявності Meta не має.
 */
enum FeedChannel: string
{
    case Google = 'google';
    case Meta = 'meta';

    /** Префікс файлу й адреси: public/feeds/<префікс><мова>.xml. */
    public function prefix(): string
    {
        return match ($this) {
            self::Google => 'google-merchant-',
            self::Meta => 'meta-',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Merchant Center',
            self::Meta => 'Meta Commerce Manager',
        };
    }

    public function route(): string
    {
        return match ($this) {
            self::Google => 'site_merchant_feed',
            self::Meta => 'site_meta_feed',
        };
    }

    public function titleMax(): int
    {
        return self::Meta === $this ? 200 : 150;
    }

    public function descriptionMax(): int
    {
        return self::Meta === $this ? 9999 : 5000;
    }

    public function additionalImagesMax(): int
    {
        return self::Meta === $this ? 20 : 10;
    }

    public function brandMax(): int
    {
        return self::Meta === $this ? 100 : 70;
    }

    /** Meta відхиляє товар без бренду: бренд картки → «бренд за замовчуванням» з налаштувань → назва магазину. */
    public function requiresBrand(): bool
    {
        return self::Meta === $this;
    }

    /**
     * Значення g:availability. Під замовлення в Meta — «in stock»: офіційно приймаються лише in stock / out of stock, а
     * товар можна замовити зараз (строк — на сторінці).
     */
    public function availability(string $google): string
    {
        return match ($this) {
            self::Google => $google,
            self::Meta => 'out_of_stock' === $google ? 'out of stock' : 'in stock',
        };
    }

    /**
     * g:sale_price_effective_date «початок/кінець» ISO 8601: Google у прикладах — зсув «+0300», Meta — «+03:00».
     */
    public function dateRange(string $range): string
    {
        return self::Meta === $this ? (preg_replace('/([+-]\d{2})(\d{2})(?=\/|$)/', '$1:$2', $range) ?? $range) : $range;
    }

    /** Поля Google, яких нема в специфікації Meta: не виводяться, щоб каталог не збирав попереджень. */
    public function supports(string $field): bool
    {
        return self::Google === $this
            || !\in_array($field, ['availability_date', 'multipack', 'unit_pricing_measure', 'unit_pricing_base_measure', 'identifier_exists'], true);
    }

    public function fileName(string $locale): string
    {
        return $this->prefix().$locale.'.xml';
    }
}

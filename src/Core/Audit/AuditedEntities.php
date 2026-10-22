<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\OptionValue;
use App\Core\Catalog\Entity\OptionValueTranslation;
use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\Entity\Setting;
use App\Core\Shared\Translation\AbstractTranslation;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Що журналюється (E15 PR-1): товар, варіант, поріг ціни, категорія, сторінка, запис блогу, акція; з рев'ю C (#68, L4) —
 * значення опцій товару (ціна рядка залежить від них) і налаштування магазину (значення-секрети `enc:v1:…` — маскою).
 * Переклади пишуться на батьківську сутність з локаллю (рядок «товар #5, uk, description»), а не як окремі сутності.
 * Технічні поля (updated_at, хеш фіду) пропускаються. Характеристики, медіа у v1 не журналюються — у пакетах змін їх нема.
 */
final class AuditedEntities
{
    /** @var array<class-string, string> */
    private const array TYPES = [
        Product::class => 'product',
        ProductVariant::class => 'product_variant',
        PriceTier::class => 'price_tier',
        Category::class => 'category',
        Page::class => 'page',
        Post::class => 'post',
        Promotion::class => 'promotion',
        OptionValue::class => 'option_value',
        Setting::class => 'setting',
    ];

    /** @var list<class-string<AbstractTranslation>> переклади, що пишуться на батька з локаллю */
    private const array TRANSLATIONS = [ProductTranslation::class, CategoryTranslation::class, PageTranslation::class, PostTranslation::class, OptionValueTranslation::class];

    /** Тип, рядки якого в CRUD журналу бачить лише ROLE_ADMIN (налаштування магазину — ADR D10). */
    public const string ADMIN_ONLY_TYPE = 'setting';

    /** Конверт зашифрованого значення налаштування (E15 BYOK, SecretBox): у журналі — лише маска з останніми 4 символами. */
    private const string SECRET_ENVELOPE = '/^enc:v1:([^:]{0,4}):/';

    /** Українські назви для текстів без перекладача (повідомлення API); адмінка — typeMessage(), ключі entity_type.* */
    private const array LABELS = [
        'product' => 'Товар',
        'product_variant' => 'Варіант',
        'price_tier' => 'Поріг ціни',
        'category' => 'Категорія',
        'page' => 'Сторінка',
        'post' => 'Запис блогу',
        'promotion' => 'Акція',
        'option_value' => 'Опція товару',
        'setting' => 'Налаштування',
        'api_token' => 'API-токен',
        // пакет змін (E15 PR-3): рядки apply/reject пише ChangesetApplier через AdminAuditLog, слухач цей тип не веде
        'changeset' => 'Пакет змін',
    ];

    /** Назви полів для списку журналу (поля сутностей і перекладів) українською; адмінка — ключі audit.field_name.*; невідоме — як є */
    private const array FIELD_LABELS = [
        'name' => 'Назва', 'title' => 'Заголовок', 'slug' => 'Адреса (slug)', 'description' => 'Опис', 'shortDescription' => 'Короткий опис',
        'body' => 'Текст', 'content' => 'Текст', 'metaTitle' => 'SEO-заголовок', 'metaDescription' => 'SEO-опис', 'h1' => 'Заголовок H1',
        'price' => 'Ціна', 'priceMinor' => 'Ціна', 'oldPriceMinor' => 'Стара ціна', 'stock' => 'Залишок', 'sku' => 'Артикул',
        'active' => 'Активний', 'featured' => 'На головній', 'position' => 'Порядок', 'parent' => 'Батьківська категорія',
        'brand' => 'Бренд', 'categories' => 'Категорії', 'value' => 'Значення', 'label' => 'Підпис', 'discount' => 'Знижка',
        'startsAt' => 'Початок', 'endsAt' => 'Кінець', 'unit' => 'Одиниця', 'stockPolicy' => 'Наявність',
    ];

    private const array IGNORED_FIELDS = ['createdAt', 'updatedAt', 'sourceUpdatedAt', 'sourceHash', 'studioSyncedAt', 'studioGroupId'];

    public static function isAudited(object $entity): bool
    {
        return isset(self::TYPES[$entity::class]) || \in_array($entity::class, self::TRANSLATIONS, true);
    }

    /** @return array{type: string, root: object, locale: string|null}|null */
    public static function target(object $entity): ?array
    {
        if (isset(self::TYPES[$entity::class])) {
            return ['type' => self::TYPES[$entity::class], 'root' => $entity, 'locale' => null];
        }
        [$root, $locale] = match (true) {
            $entity instanceof ProductTranslation => [$entity->getProduct(), $entity->getLocale()],
            $entity instanceof CategoryTranslation => [$entity->getCategory(), $entity->getLocale()],
            $entity instanceof PageTranslation => [$entity->getPage(), $entity->getLocale()],
            $entity instanceof PostTranslation => [$entity->getPost(), $entity->getLocale()],
            $entity instanceof OptionValueTranslation => [$entity->getOptionValue(), $entity->getLocale()],
            default => [null, null],
        };
        if (null === $root) {
            return null;
        }

        return ['type' => self::TYPES[$root::class] ?? 'unknown', 'root' => $root, 'locale' => $locale];
    }

    public static function isIgnoredField(string $field): bool
    {
        return \in_array($field, self::IGNORED_FIELDS, true);
    }

    /**
     * Значення для журналу без секретів: зашифроване налаштування → маска «…abcd» (як у SecretBox::mask). Для знімка
     * (field = null, масив поле → значення) — по кожному полю.
     */
    public static function redact(object $root, ?string $field, mixed $value): mixed
    {
        if (!$root instanceof Setting) {
            return $value;
        }
        if (null === $field && \is_array($value)) {
            foreach ($value as $name => $v) {
                $value[$name] = self::redact($root, (string) $name, $v);
            }

            return $value;
        }
        if (\is_string($value) && 1 === preg_match(self::SECRET_ENVELOPE, $value, $m)) {
            return '…'.$m[1];
        }

        return $value;
    }

    public static function typeLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** Назва поля для списку журналу: з перекладачем — мовою адмінки (audit.field_name.*), без нього — українська; невідоме — як є. */
    public static function fieldLabel(string $field, ?TranslatorInterface $translator = null): string
    {
        if (!isset(self::FIELD_LABELS[$field])) {
            return $field;
        }

        return null === $translator ? self::FIELD_LABELS[$field] : $translator->trans('audit.field_name.'.$field, [], 'admin');
    }

    /** Назва типу мовою адмінки (admin+intl-icu, entity_type.*); невідомий тип — код як є. */
    public static function typeMessage(string $type): TranslatableMessage
    {
        return isset(self::LABELS[$type]) ? new TranslatableMessage('entity_type.'.$type, [], 'admin') : new TranslatableMessage($type, [], 'admin');
    }

    /** @return array<string, TranslatableMessage> код → назва (ChoiceFilter::setTranslatableChoices у журналі адмінки) */
    public static function typeChoices(): array
    {
        $choices = [];
        foreach (array_keys(self::LABELS) as $type) {
            $choices[$type] = self::typeMessage($type);
        }

        return $choices;
    }

    /** Підпис сутності для журналу: SKU, назва, заголовок — що є. */
    public static function label(object $root): ?string
    {
        $label = match (true) {
            $root instanceof ProductVariant => $root->getSku(),
            $root instanceof PriceTier => \sprintf('%s від %s', $root->getVariant()->getSku(), rtrim(rtrim($root->getMinQuantity(), '0'), '.')),
            $root instanceof Promotion => $root->getName(),
            $root instanceof OptionValue => \sprintf('%s / %s: %s', $root->getGroup()->getProduct()->getDefaultVariant()->getSku(), $root->getGroup()->getCode(), $root),
            method_exists($root, '__toString') => (string) $root,
            default => null,
        };
        $label = null === $label ? null : trim($label);

        return '' === $label ? null : mb_substr((string) $label, 0, 255);
    }
}

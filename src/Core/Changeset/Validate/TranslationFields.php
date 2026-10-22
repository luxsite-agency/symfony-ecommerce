<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Shared\Translation\AbstractTranslation;
use InvalidArgumentException;

/**
 * Білий список полів set_translation на сутність (E15 §3): назва/заголовок, слаг, описи, SEO. Усе поза списком — помилка
 * field_not_allowed; так FAQ, обкладинки, шляхи категорій і будь-які майбутні поля не стають доступними AI випадково.
 * Для кожного поля — геттер, сеттер, межа довжини (колонка) і чи це HTML (ContentHtml).
 */
final class TranslationFields
{
    public const string PRODUCT = 'product';
    public const string CATEGORY = 'category';
    public const string PAGE = 'page';
    public const string POST = 'post';
    /** Межа колонки TEXT у байтах; поля з такою max міряються strlen, не mb_strlen */
    public const int TEXT_BYTES = 65535;

    /** @var array<string, array<string, array{get: string, set: string, max: int|null, html: bool, required: bool}>> */
    private const array FIELDS = [
        self::PRODUCT => [
            'name' => ['get' => 'getName', 'set' => 'setName', 'max' => 255, 'html' => false, 'required' => true],
            'slug' => ['get' => 'getSlug', 'set' => 'setSlug', 'max' => 190, 'html' => false, 'required' => false],
            'short_description' => ['get' => 'getShortDescription', 'set' => 'setShortDescription', 'max' => 65535, 'html' => false, 'required' => false],
            'description' => ['get' => 'getDescription', 'set' => 'setDescription', 'max' => null, 'html' => true, 'required' => false],
            'meta_title' => ['get' => 'getMetaTitle', 'set' => 'setMetaTitle', 'max' => 255, 'html' => false, 'required' => false],
            'meta_description' => ['get' => 'getMetaDescription', 'set' => 'setMetaDescription', 'max' => 500, 'html' => false, 'required' => false],
        ],
        self::CATEGORY => [
            'name' => ['get' => 'getName', 'set' => 'setName', 'max' => 255, 'html' => false, 'required' => true],
            'slug' => ['get' => 'getSlug', 'set' => 'setSlug', 'max' => 190, 'html' => false, 'required' => false],
            'description' => ['get' => 'getDescription', 'set' => 'setDescription', 'max' => null, 'html' => true, 'required' => false],
            'h1' => ['get' => 'getH1', 'set' => 'setH1', 'max' => 255, 'html' => false, 'required' => false],
            'meta_title' => ['get' => 'getMetaTitle', 'set' => 'setMetaTitle', 'max' => 255, 'html' => false, 'required' => false],
            'meta_description' => ['get' => 'getMetaDescription', 'set' => 'setMetaDescription', 'max' => 500, 'html' => false, 'required' => false],
        ],
        self::PAGE => [
            'title' => ['get' => 'getTitle', 'set' => 'setTitle', 'max' => 255, 'html' => false, 'required' => true],
            'slug' => ['get' => 'getSlug', 'set' => 'setSlug', 'max' => 190, 'html' => false, 'required' => false],
            'body' => ['get' => 'getBody', 'set' => 'setBody', 'max' => null, 'html' => true, 'required' => false],
            'meta_title' => ['get' => 'getMetaTitle', 'set' => 'setMetaTitle', 'max' => 255, 'html' => false, 'required' => false],
            'meta_description' => ['get' => 'getMetaDescription', 'set' => 'setMetaDescription', 'max' => 500, 'html' => false, 'required' => false],
        ],
        self::POST => [
            'title' => ['get' => 'getTitle', 'set' => 'setTitle', 'max' => 255, 'html' => false, 'required' => true],
            'slug' => ['get' => 'getSlug', 'set' => 'setSlug', 'max' => 190, 'html' => false, 'required' => false],
            'excerpt' => ['get' => 'getExcerpt', 'set' => 'setExcerpt', 'max' => 65535, 'html' => false, 'required' => false],
            'body' => ['get' => 'getBody', 'set' => 'setBody', 'max' => null, 'html' => true, 'required' => false],
            'meta_title' => ['get' => 'getMetaTitle', 'set' => 'setMetaTitle', 'max' => 255, 'html' => false, 'required' => false],
            'meta_description' => ['get' => 'getMetaDescription', 'set' => 'setMetaDescription', 'max' => 500, 'html' => false, 'required' => false],
        ],
    ];

    /** @var array<string, class-string> */
    private const array ROOTS = [
        self::PRODUCT => Product::class,
        self::CATEGORY => Category::class,
        self::PAGE => Page::class,
        self::POST => Post::class,
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return class-string */
    public static function rootClass(string $type): string
    {
        return self::ROOTS[$type] ?? throw new InvalidArgumentException(\sprintf('Невідомий тип сутності "%s"', $type));
    }

    /** @return list<string> */
    public static function allowed(string $type): array
    {
        return array_keys(self::FIELDS[$type] ?? []);
    }

    public static function isAllowed(string $type, string $field): bool
    {
        return isset(self::FIELDS[$type][$field]);
    }

    public static function isHtml(string $type, string $field): bool
    {
        return self::FIELDS[$type][$field]['html'] ?? false;
    }

    public static function isRequired(string $type, string $field): bool
    {
        return self::FIELDS[$type][$field]['required'] ?? false;
    }

    public static function maxLength(string $type, string $field): ?int
    {
        return self::FIELDS[$type][$field]['max'] ?? null;
    }

    /** Поле назви/заголовка (обов'язкове для нового перекладу) */
    public static function requiredField(string $type): string
    {
        foreach (self::FIELDS[$type] ?? [] as $field => $meta) {
            if ($meta['required']) {
                return $field;
            }
        }
        throw new InvalidArgumentException(\sprintf('Невідомий тип сутності "%s"', $type));
    }

    /** Поточне значення поля перекладу; порожній рядок читається як null, щоб expect: null збігався з "" у БД. */
    public static function get(string $type, AbstractTranslation $translation, string $field): ?string
    {
        $getter = self::FIELDS[$type][$field]['get'] ?? throw new InvalidArgumentException(\sprintf('Поле "%s" не дозволене для %s', $field, $type));
        /** @var string|null $value */
        $value = \call_user_func([$translation, $getter]);

        return null === $value || '' === $value ? null : $value;
    }

    public static function set(string $type, AbstractTranslation $translation, string $field, ?string $value): void
    {
        $setter = self::FIELDS[$type][$field]['set'] ?? throw new InvalidArgumentException(\sprintf('Поле "%s" не дозволене для %s', $field, $type));
        \call_user_func([$translation, $setter], self::isRequired($type, $field) ? (string) $value : $value);
    }
}

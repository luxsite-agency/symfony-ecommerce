<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use App\Core\Shared\Text\Slug;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок перекладу сутності однією мовою. Набір мов — налаштування магазину, не константа (ADR Rev 8).
 * Власник перекладу (ManyToOne) і unique(owner, locale) оголошує кожен конкретний клас.
 */
#[ORM\MappedSuperclass]
abstract class AbstractTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $locale;

    public function __construct(string $locale)
    {
        $this->locale = self::normalizeLocale($locale);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** "uk", "en_US"; "en-us" → "en_US". */
    public static function normalizeLocale(string $locale): string
    {
        $parts = preg_split('/[-_]/', trim($locale)) ?: [];
        $normalized = strtolower($parts[0] ?? '');
        if (isset($parts[1])) {
            $normalized .= '_'.strtoupper($parts[1]);
        }
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $normalized) || \count($parts) > 2) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не код локалі: "%s"', $locale), 'error.translation.invalid_locale', ['value' => $locale]);
        }

        return $normalized;
    }

    /** Нічого не заповнено: такий переклад форма адмінки не зберігає. */
    abstract public function isBlank(): bool;

    /**
     * Рядок без крайніх пробілів, не довший за $maxLength символів. Порожній дозволено: обов'язковість назви
     * лише для мови за замовчуванням (налаштування магазину), тому її перевіряє форма, не сутність.
     */
    protected static function requireText(string $value, string $field, int $maxLength = 255): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new TranslatableInvalidArgumentException(\sprintf('Поле "%s" не може бути довшим за %d символів', $field, $maxLength), 'error.translation.field_too_long', ['field' => $field, 'max' => (string) $maxLength]);
        }

        return $value;
    }

    /** Слаг або null для порожнього рядка. */
    protected static function optionalSlug(?string $slug): ?string
    {
        $slug = trim((string) $slug);

        return '' === $slug ? null : Slug::assertValid($slug);
    }
}

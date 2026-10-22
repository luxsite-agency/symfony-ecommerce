<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Text;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Слаги без «дефіса на мʼякому знаку» (seo-tech-notes п. 12: viys-kovoi, mul-tykam, nastil-nyy виглядають як помилка).
 *
 * Українська — офіційна транслітерація (постанова КМУ № 55 від 27.01.2010): ь і апостроф не передаються, й → i, на
 * початку слова є/ї/й/ю/я → ye/yi/y/yu/ya, зг → zgh; «військової» → viiskovoi, «настільний» → nastilnyi. Інші мови —
 * стандартний слагер Symfony, але мʼякий/твердий знак і апострофи прибираються до нього (інакше ICU дає «'» → «-»).
 *
 * Лише для URL-слагів (товари, категорії, сторінки, записи): `#[Target('urlSlugger')] SluggerInterface`. Глобальний
 * `slugger` НЕ декорується: з нього імпорт будує машинні коди характеристик і значень та слаги брендів, за якими
 * знаходить уже наявні записи (chornyy, levi-s) — інша транслітерація дала б дублі при повторному імпорті.
 */
#[AsAlias(SluggerInterface::class, target: 'urlSlugger')]
final class UkrainianSlugger implements SluggerInterface, LocaleAwareInterface
{
    private const array MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ю' => 'iu', 'я' => 'ia', 'ь' => '',
    ];
    private const array WORD_START = ['є' => 'ye', 'ї' => 'yi', 'й' => 'y', 'ю' => 'yu', 'я' => 'ya'];
    private const string APOSTROPHES = "'’ʼ`´";

    public function __construct(private readonly SluggerInterface $inner)
    {
    }

    /** Сервіс slugger — kernel.locale_aware: мова запиту за замовчуванням, коли $locale не передано. */
    public function setLocale(string $locale): void
    {
        if ($this->inner instanceof LocaleAwareInterface) {
            $this->inner->setLocale($locale);
        }
    }

    public function getLocale(): string
    {
        return $this->inner instanceof LocaleAwareInterface ? $this->inner->getLocale() : 'en';
    }

    public function slug(string $string, string $separator = '-', ?string $locale = null): AbstractUnicodeString
    {
        if (str_starts_with($locale ?? $this->getLocale(), 'uk')) {
            $string = self::ukrainian($string);
        } else {
            $string = str_replace(['ь', 'Ь', 'ъ', 'Ъ', ...mb_str_split(self::APOSTROPHES)], '', $string);
        }

        return $this->inner->slug($string, $separator, $locale);
    }

    /** Кирилиця → латиниця за КМУ-2010; решта символів (латиниця, цифри, розділові знаки) — як є для внутрішнього слагера. */
    public static function ukrainian(string $text): string
    {
        $text = str_replace(mb_str_split(self::APOSTROPHES), '', mb_strtolower($text));
        $text = str_replace('зг', 'зґг', $text); // зг → zgh (щоб не прочиталось як «жи»): ґ тимчасово → g + h
        $out = '';
        $prevLetter = false;
        foreach (mb_str_split($text) as $char) {
            $isLetter = 1 === preg_match('/\p{L}/u', $char);
            if (!$prevLetter && isset(self::WORD_START[$char])) {
                $out .= self::WORD_START[$char];
            } else {
                $out .= self::MAP[$char] ?? $char;
            }
            $prevLetter = $isLetter;
        }

        return $out;
    }
}

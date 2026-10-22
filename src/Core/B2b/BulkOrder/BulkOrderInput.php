<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\BulkOrder;

use App\Core\Shared\Quantity\Quantity;
use InvalidArgumentException;

/**
 * Розбір списку для замовлення списком (E13 PR-7): текст із поля або вміст CSV. Рядок — «SKU кількість», роздільник
 * крапка з комою, кома, табуляція або пробіл; кількості нема — 1; зайві колонки (назва, ціна) ігноруються; заголовок
 * CSV (кількість не число в першому непорожньому рядку) показується як header. Кодування: UTF-8 (BOM знімається), інакше Windows-1251
 * (Excel на Windows зберігає CSV саме так). Ліміти: розмір і кількість рядків, щоб один POST не розбирав мегабайти.
 */
final class BulkOrderInput
{
    public const int MAX_LINES = 500;
    public const int MAX_BYTES = 262_144;

    /**
     * @return list<BulkOrderLine>
     *
     * @throws BulkOrderException empty | too_large | too_many_lines
     */
    public static function parse(string $raw): array
    {
        if (\strlen($raw) > self::MAX_BYTES) {
            throw new BulkOrderException('too_large');
        }
        $text = self::toUtf8($raw);
        $lines = preg_split('/\R/u', $text) ?: [];
        $parsed = [];
        $first = true;
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            [$sku, $quantity] = self::columns($line);
            if ('' === $sku) {
                continue;
            }
            if ($first && null !== $quantity && !is_numeric(str_replace(',', '.', $quantity))) {
                // перший непорожній рядок з нечисловою кількістю — заголовок CSV («Артикул;Кількість»): у таблиці як header,
                // а якщо такий SKU є в каталозі — звичайний рядок з помилкою кількості (BulkOrderResolver)
                $parsed[] = new BulkOrderLine($i + 1, $sku, '0.000', 'header');
                $first = false;
                continue;
            }
            $first = false;
            try {
                $quantity = null === $quantity ? '1.000' : Quantity::positive($quantity);
                $parsed[] = new BulkOrderLine($i + 1, $sku, $quantity);
            } catch (InvalidArgumentException) {
                $parsed[] = new BulkOrderLine($i + 1, $sku, '0.000', 'invalid_quantity');
            }
            if (\count($parsed) > self::MAX_LINES) {
                throw new BulkOrderException('too_many_lines');
            }
        }
        if ([] === $parsed) {
            throw new BulkOrderException('empty');
        }

        return $parsed;
    }

    /**
     * SKU і кількість (null — колонки нема). Спершу «;» і табуляція (CSV з Excel), далі кома лише як роздільник колонок,
     * не десяткова («ABC,3» так, «2,5» ні), далі пробіли: «ABC-1;2,5» і «ABC-1 2,5» дають 2.5.
     *
     * @return array{string, ?string}
     */
    private static function columns(string $line): array
    {
        $parts = preg_split('/[;\t]/', $line) ?: [];
        if (\count($parts) < 2) {
            $parts = preg_split('/,(?!\d)|(?<!\d),/', $line) ?: [];
        }
        if (\count($parts) < 2) {
            $parts = preg_split('/\s+/', $line) ?: [];
        }
        $sku = mb_substr(trim((string) ($parts[0] ?? ''), " \t\"'"), 0, 64);
        $quantity = isset($parts[1]) ? trim($parts[1], " \t\"'") : null;

        return [$sku, '' === $quantity ? null : $quantity];
    }

    private static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    }
}

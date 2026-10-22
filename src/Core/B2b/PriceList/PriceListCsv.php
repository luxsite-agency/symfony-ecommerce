<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\PriceList;

use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use NumberFormatter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV прайс-листа для Excel: UTF-8 з BOM, роздільник «;», рядки CRLF, десятковий роздільник мови (uk/ru — кома, інакше
 * Excel тієї ж мови читає ціну як текст). Комірки захищені від формул (OWASP CSV injection): значення, що починається з
 * = + - @ табуляції чи CR, отримує апостроф спереду, щоб Excel/LibreOffice показали текст, а не виконали =HYPERLINK().
 * Відповідь потокова: рядки йдуть у php://output пакетами генератора, без збирання всього каталогу в пам'яті; ліміт часу
 * PHP знімається на час потоку, а останній рядок — службовий трейлер «# rows: N» (рев'ю #63 M2): якщо з'єднання обірвав
 * nginx чи проксі, файл без трейлера — неповний, і це видно. Контактів магазину у файлі нема навмисно: прайс пересилають далі.
 */
final class PriceListCsv
{
    public const string SEPARATOR = ';';
    public const string BOM = "\xEF\xBB\xBF";
    /** Службовий останній рядок: кількість рядків даних; без нього файл обірваний. */
    public const string TRAILER = '# rows:';
    private const array COLUMNS = ['sku', 'name', 'category', 'unit', 'from_quantity', 'group_price', 'retail_price', 'currency', 'availability', 'stock', 'min_quantity', 'quantity_step', 'pack_quantity'];

    public function __construct(private readonly PriceListGenerator $generator, private readonly TranslatorInterface $translator)
    {
    }

    public function response(CustomerGroup $group, string $locale): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($group, $locale): void {
            // Файл на весь каталог довший за max_execution_time (60 с у prod.ini): ліміт знімаємо на час потоку, запит однаково
            // закінчується разом з ним; обрив клієнтом зупиняє скрипт, щоб не генерувати нікому не потрібний хвіст.
            set_time_limit(0);
            ignore_user_abort(false);
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            $this->write($out, $group, $locale);
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::fileName($group)));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @param resource $out */
    public function write($out, CustomerGroup $group, string $locale): void
    {
        $decimal = (string) ((new NumberFormatter($locale, NumberFormatter::DECIMAL))->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.');
        fwrite($out, self::BOM);
        $this->line($out, array_map(fn (string $column): string => $this->translator->trans('site.account.price_list.column.'.$column, [], null, $locale), self::COLUMNS));
        $written = 0;
        foreach ($this->generator->rows($group, $locale) as $row) {
            $this->line($out, [
                $row->sku,
                $row->name,
                $row->category,
                $this->translator->trans('site.account.price_list.unit.'.$row->unit, [], null, $locale),
                self::quantity($row->fromQuantity, $decimal),
                self::money($row->groupPrice, $decimal),
                self::money($row->retailPrice, $decimal),
                $row->groupPrice->currency,
                $this->translator->trans('site.account.price_list.availability.'.$row->availability, [], null, $locale),
                null !== $row->stock ? self::quantity($row->stock, $decimal) : '',
                self::quantity($row->minQuantity, $decimal),
                self::quantity($row->quantityStep, $decimal),
                null !== $row->packQuantity ? self::quantity($row->packQuantity, $decimal) : '',
            ]);
            if (0 === ++$written % 200) {
                fflush($out);
                if (\function_exists('flush') && \PHP_SAPI !== 'cli') {
                    flush();
                }
            }
        }
        fwrite($out, \sprintf("%s %d\r\n", self::TRAILER, $written));
        fflush($out);
    }

    public static function fileName(CustomerGroup $group): string
    {
        return \sprintf('price-list-%s-%s.csv', $group->getCode(), date('Y-m-d'));
    }

    /** Значення, безпечне для табличного процесора: не починається з символу формули. */
    public static function safe(string $value): string
    {
        return '' !== $value && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    /**
     * @param resource     $out
     * @param list<string> $cells
     */
    private function line($out, array $cells): void
    {
        fputcsv($out, array_map(self::safe(...), $cells), self::SEPARATOR, '"', '', "\r\n");
    }

    private static function money(Money $money, string $decimal): string
    {
        return str_replace('.', $decimal, $money->toDecimalString(2));
    }

    /** DECIMAL(12,3) без зайвих нулів: 1.000 → 1, 1.500 → 1,5. */
    private static function quantity(string $quantity, string $decimal): string
    {
        $trimmed = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;

        return str_replace('.', $decimal, '' === $trimmed ? '0' : $trimmed);
    }
}

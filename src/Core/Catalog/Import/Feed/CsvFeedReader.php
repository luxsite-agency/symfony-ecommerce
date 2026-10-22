<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\ProductImportRow;
use App\Core\Catalog\Import\VariantImportRow;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * CSV постачальника, потоково через fgetcsv. Колонки за заголовком (регістр не важить):
 *  product_id, external_id|offer_id, sku, name[_uk|_ru], description[_uk|_ru], price, old_price, currency, stock|quantity,
 *  barcode, brand|vendor, category (шлях через « / » або « > »), category_<мова> (той самий шлях іншою мовою),
 *  images (URL через кому, завантажує MediaImporter), updated_at,
 *  attr:<код> — характеристика картки, axis:<код> — вісь варіанта.
 * Рядки з однаковим product_id поспіль — варіанти однієї картки; без product_id — картка з одним варіантом (product_id = external_id).
 * Порожня ціна в усіх варіантів — картка «ціна за запитом» (price_mode on_request, ціна 0 як службова), не «0,00 грн»;
 * порожня ціна лише в частини варіантів — ці варіанти вимкнені (купити за 0 не можна, «за запитом» — рівень картки).
 */
#[AsTaggedItem(index: 'csv')]
final class CsvFeedReader implements FeedReaderInterface
{
    public function getFormat(): string
    {
        return 'csv';
    }

    public function supports(string $path): bool
    {
        return (bool) preg_match('/\.(csv|tsv|txt)$/i', $path);
    }

    public function read(string $path, FeedOptions $options): iterable
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new RuntimeException(\sprintf('Не вдалося відкрити фід %s', $path));
        }
        try {
            $firstLine = fgets($handle);
            if (false === $firstLine) {
                return;
            }
            $delimiter = $options->csvDelimiter ?? $this->detectDelimiter($firstLine);
            $header = array_map(static fn (string $h): string => mb_strtolower(trim(ltrim($h, "\xEF\xBB\xBF"))), str_getcsv($firstLine, $delimiter, '"', '\\'));

            $groupKey = null;
            $group = [];
            $lineNo = 1;
            while (false !== ($cells = fgetcsv($handle, 0, $delimiter, '"', '\\'))) {
                ++$lineNo;
                if ([null] === $cells) {
                    continue;
                }
                $record = [];
                foreach ($header as $i => $column) {
                    $record[$column] = trim((string) ($cells[$i] ?? ''));
                }
                $productId = $record['product_id'] ?? '';
                $key = '' !== $productId ? $productId : ($record['external_id'] ?? $record['offer_id'] ?? $record['sku'] ?? (string) $lineNo);
                if (null !== $groupKey && $key !== $groupKey && [] !== $group) {
                    yield $this->row($groupKey, $group, $options);
                    $group = [];
                }
                $groupKey = $key;
                $group[] = $record + ['_line' => (string) $lineNo];
            }
            if (null !== $groupKey && [] !== $group) {
                yield $this->row($groupKey, $group, $options);
            }
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $best = ',';
        $bestCount = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @param non-empty-list<array<string, string>> $records */
    private function row(string $externalId, array $records, FeedOptions $options): ProductImportRow|InvalidImportRowException
    {
        $first = $records[0];
        try {
            $names = [];
            foreach ($first as $column => $value) {
                if ('' === $value) {
                    continue;
                }
                if ('name' === $column) {
                    $names[$options->locale] = $value;
                } elseif (preg_match('/^name_([a-z]{2})$/', $column, $m)) {
                    $names[$m[1]] = $value;
                }
            }
            $descriptions = [];
            foreach ($first as $column => $value) {
                if ('' === $value) {
                    continue;
                }
                if ('description' === $column) {
                    $descriptions[$options->locale] = $value;
                } elseif (preg_match('/^description_([a-z]{2})$/', $column, $m)) {
                    $descriptions[$m[1]] = $value;
                }
            }
            $attributes = [];
            foreach ($first as $column => $value) {
                if (str_starts_with($column, 'attr:') && '' !== $value) {
                    $attributes[substr($column, 5)] = str_contains($value, '|') ? array_values(array_filter(array_map(trim(...), explode('|', $value)))) : $value;
                }
            }
            $paths = [];
            $splitPath = static fn (string $raw): array => array_values(array_filter(array_map(trim(...), preg_split('/\s*[\/>]\s*/', $raw) ?: [])));
            if ('' !== ($first['category'] ?? '')) {
                $paths[] = $splitPath($first['category']);
            }
            $categoryTranslations = [];
            foreach ($first as $column => $value) {
                if ('' !== $value && [] !== $paths && preg_match('/^category_([a-z]{2})$/', $column, $m)) {
                    $categoryTranslations[$m[1]] = [$splitPath($value)];
                }
            }
            $pricedRecords = array_filter($records, static fn (array $r): bool => '' !== str_replace([',', ' '], '', $r['price'] ?? ''));
            $onRequest = [] === $pricedRecords;
            $variants = [];
            foreach ($records as $record) {
                $axes = [];
                foreach ($record as $column => $value) {
                    if (str_starts_with($column, 'axis:') && '' !== $value) {
                        $axes[substr($column, 5)] = $value;
                    }
                }
                $currency = strtoupper($record['currency'] ?? '') ?: $options->currency;
                $priceRaw = str_replace([',', ' '], ['.', ''], $record['price'] ?? '');
                $price = Money::fromDecimalString('' === $priceRaw ? '0' : $priceRaw, $currency);
                $oldRaw = str_replace([',', ' '], ['.', ''], $record['old_price'] ?? '');
                $variants[] = new VariantImportRow(
                    externalId: $record['external_id'] ?? $record['offer_id'] ?? ($record['sku'] ?? ''),
                    sku: $record['sku'] ?? ($record['external_id'] ?? ''),
                    priceMinor: $price->amount,
                    currency: $currency,
                    stock: $record['stock'] ?? $record['quantity'] ?? '0',
                    oldPriceMinor: '' === $oldRaw ? null : Money::fromDecimalString($oldRaw, $currency)->amount,
                    barcode: ($record['barcode'] ?? '') ?: null,
                    axes: $axes,
                    active: $onRequest || '' !== $priceRaw,
                );
            }
            $updatedRaw = $first['updated_at'] ?? '';

            return new ProductImportRow(
                externalId: $externalId,
                names: $names,
                variants: $variants,
                descriptions: $descriptions,
                brand: ($first['brand'] ?? $first['vendor'] ?? '') ?: null,
                categoryPaths: $paths,
                attributes: $attributes,
                mediaUrls: '' === ($first['images'] ?? '') ? [] : array_values(array_filter(array_map(trim(...), explode(',', $first['images'])))),
                sourceUpdatedAt: '' === $updatedRaw ? null : new DateTimeImmutable($updatedRaw),
                priceMode: $onRequest ? PriceMode::OnRequest : PriceMode::Fixed,
                categoryTranslations: $categoryTranslations,
            );
        } catch (Exception $e) {
            return new InvalidImportRowException(\sprintf('CSV рядок %s (%s): %s', $first['_line'] ?? '?', $externalId, $e->getMessage()), 0, $e);
        }
    }
}

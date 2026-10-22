<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use App\Core\Catalog\Import\ProductImportRow;
use App\Core\Catalog\Import\VariantImportRow;
use App\Core\Shared\Money\Money;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use XMLReader;

/**
 * YML (Yandex Market Language / Prom / Hotline): <shop><categories><category id parentId>…</categories><offers><offer id available group_id>.
 * XMLReader читає потоково по <offer>; категорії (невеликі) збираються в мапу заздалегідь одним проходом до <offers>.
 * offer з group_id — варіанти однієї картки (externalId картки = group_id), без group_id — картка з одним варіантом.
 * Варіанти однієї групи йдуть у фіді поспіль; розкидані по файлу — потраплять у різні рядки і будуть злиті імпортом.
 */
#[AsTaggedItem(index: 'yml')]
final class YmlFeedReader implements FeedReaderInterface
{
    /**
     * Без мережі й без підстановки сутностей (XXE, консиліум безпеки 10.10 п. 1): LIBXML_NOENT розгортав би
     * <!ENTITY e SYSTEM "file:///…"> у текст опису товару. Фід із DOCTYPE/ENTITY відхиляється ще до парсера (assertNoDoctype).
     */
    public const int LIBXML_OPTIONS = \LIBXML_NONET;
    /** Стільки байтів прологу переглядається до кореневого елемента; довший пролог (коментарі, PI) — теж відмова */
    private const int PROLOG_MAX_BYTES = 1024 * 1024;

    public function getFormat(): string
    {
        return 'yml';
    }

    public function supports(string $path): bool
    {
        if (preg_match('/\.(xml|yml)$/i', $path)) {
            return true;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 512);

        return str_contains($head, '<yml_catalog') || str_contains($head, '<offers');
    }

    public function read(string $path, FeedOptions $options): iterable
    {
        $this->assertNoDoctype($path);
        $categories = $this->readCategories($path);

        $reader = new XMLReader();
        if (!$reader->open($path, null, self::LIBXML_OPTIONS)) {
            throw new RuntimeException(\sprintf('Не вдалося відкрити фід %s', $path));
        }
        try {
            $group = null;
            $groupOffers = [];
            while ($reader->read()) {
                $this->rejectDoctype($reader, $path);
                if (XMLReader::ELEMENT !== $reader->nodeType || 'offer' !== $reader->localName) {
                    continue;
                }
                $xml = $reader->readOuterXml();
                $offer = @simplexml_load_string($xml, null, self::LIBXML_OPTIONS);
                if (false === $offer) {
                    yield new InvalidImportRowException('offer: зламаний XML');
                    continue;
                }
                $groupId = (string) ($offer['group_id'] ?? '');
                if ('' !== $groupId && $groupId === $group) {
                    $groupOffers[] = $offer;
                    continue;
                }
                if ([] !== $groupOffers) {
                    yield $this->row($groupOffers, $categories, $options);
                }
                $group = '' !== $groupId ? $groupId : null;
                $groupOffers = [$offer];
                if (null === $group) {
                    yield $this->row($groupOffers, $categories, $options);
                    $groupOffers = [];
                }
            }
            if ([] !== $groupOffers) {
                yield $this->row($groupOffers, $categories, $options);
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * YML не має DTD, тож <!DOCTYPE (зокрема з <!ENTITY) у фіді — ознака атаки XXE або сміття: швидка відмова до відкриття
     * парсером за сирими байтами прологу (усе до першого елемента). Не єдиний бар'єр: вузол DOC_TYPE ловить і rejectDoctype()
     * у циклах читання; libxml ≥ 2.9 без NOENT зовнішні сутності й так не вантажить.
     */
    private function assertNoDoctype(string $path): void
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new RuntimeException(\sprintf('Не вдалося відкрити фід %s', $path));
        }
        try {
            $prolog = '';
            while (!feof($handle) && \strlen($prolog) < self::PROLOG_MAX_BYTES) {
                $chunk = fread($handle, 65536);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $prolog .= $chunk;
                // кореневий елемент — перший «<» з літерою далі (коментарі, PI, DOCTYPE починаються з «<!» або «<?»)
                if (1 === preg_match('/<[A-Za-z_]/', $prolog, $m, \PREG_OFFSET_CAPTURE)) {
                    $prolog = substr($prolog, 0, (int) $m[0][1]);
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        if (1 === preg_match('/<!(DOCTYPE|ENTITY)/i', $prolog)) {
            throw new UnsafeFeedException(\sprintf('Фід %s містить DOCTYPE/ENTITY — такі файли не імпортуються (захист від XXE)', $path));
        }
        if (\strlen($prolog) >= self::PROLOG_MAX_BYTES) {
            throw new UnsafeFeedException(\sprintf('Фід %s: кореневий елемент не знайдено в перших %d байтах', $path, self::PROLOG_MAX_BYTES));
        }
    }

    /**
     * Та сама відмова вже з парсера: raw-guard обходиться коментарем «<!-- <x -->» чи PI перед DOCTYPE або кодуванням
     * UTF-16 — вузол DOC_TYPE від XMLReader не обходиться нічим.
     */
    private function rejectDoctype(XMLReader $reader, string $path): void
    {
        if (XMLReader::DOC_TYPE === $reader->nodeType) {
            throw new UnsafeFeedException(\sprintf('Фід %s містить DOCTYPE/ENTITY — такі файли не імпортуються (захист від XXE)', $path));
        }
    }

    /** @return array<string, array{name: string, parent: string|null}> */
    private function readCategories(string $path): array
    {
        $reader = new XMLReader();
        if (!$reader->open($path, null, self::LIBXML_OPTIONS)) {
            return [];
        }
        $map = [];
        try {
            while ($reader->read()) {
                $this->rejectDoctype($reader, $path);
                if (XMLReader::ELEMENT === $reader->nodeType && 'category' === $reader->localName) {
                    $id = $reader->getAttribute('id') ?? '';
                    $parent = $reader->getAttribute('parentId');
                    $map[$id] = ['name' => trim($reader->readString()), 'parent' => '' === $parent ? null : $parent];
                } elseif (XMLReader::ELEMENT === $reader->nodeType && 'offers' === $reader->localName) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $map;
    }

    /**
     * @param non-empty-list<SimpleXMLElement>                        $offers
     * @param array<string, array{name: string, parent: string|null}> $categories
     */
    private function row(array $offers, array $categories, FeedOptions $options): ProductImportRow|InvalidImportRowException
    {
        try {
            $first = $offers[0];
            $externalId = (string) ($first['group_id'] ?? '') ?: (string) ($first['id'] ?? '');
            $names = [];
            $descriptions = [];
            $name = trim((string) ($first->name ?? $first->model ?? ''));
            if ('' !== $name) {
                $names[$options->locale] = $name;
            }
            foreach (['name_ru' => 'ru', 'name_uk' => 'uk', 'name_ua' => 'uk'] as $tag => $locale) {
                if ('' !== trim((string) $first->{$tag})) {
                    $names[$locale] = trim((string) $first->{$tag});
                }
            }
            if ('' !== trim((string) $first->description)) {
                $descriptions[$options->locale] = trim((string) $first->description);
            }
            $paths = [];
            $categoryId = trim((string) $first->categoryId);
            if ('' !== $categoryId && isset($categories[$categoryId])) {
                $paths[] = $this->categoryPath($categoryId, $categories);
            }
            // характеристики картки — параметри першого offer; параметри, що відрізняються між offer-ами групи, — осі варіантів
            $paramsPerOffer = [];
            foreach ($offers as $i => $offer) {
                foreach ($offer->param as $param) {
                    $paramName = trim((string) $param['name']);
                    if ('' !== $paramName) {
                        $paramsPerOffer[$i][$paramName] = trim((string) $param);
                    }
                }
            }
            $axisNames = [];
            if (\count($offers) > 1) {
                foreach ($paramsPerOffer as $params) {
                    foreach ($params as $paramName => $value) {
                        foreach ($paramsPerOffer as $otherParams) {
                            if (($otherParams[$paramName] ?? null) !== $value) {
                                $axisNames[$paramName] = true;
                            }
                        }
                    }
                }
            }
            $attributes = array_diff_key($paramsPerOffer[0] ?? [], $axisNames);

            $variants = [];
            foreach ($offers as $i => $offer) {
                $variantAxes = array_intersect_key($paramsPerOffer[$i] ?? [], $axisNames);
                $price = Money::fromDecimalString(str_replace(',', '.', trim((string) $offer->price) ?: '0'), $options->currency);
                $oldRaw = trim((string) $offer->oldprice);
                $old = '' === $oldRaw ? null : Money::fromDecimalString(str_replace(',', '.', $oldRaw), $options->currency)->amount;
                $available = strtolower((string) ($offer['available'] ?? 'true'));
                $stockRaw = trim((string) ($offer->stock_quantity ?? $offer->quantity_in_stock ?? $offer->quantity ?? ''));
                $stock = '' !== $stockRaw ? $stockRaw : ('false' === $available ? '0' : '1');
                $variants[] = new VariantImportRow(
                    externalId: (string) ($offer['id'] ?? ''),
                    sku: trim((string) ($offer->vendorCode ?? '')) ?: (string) ($offer['id'] ?? ''),
                    priceMinor: $price->amount,
                    currency: (string) ($offer->currencyId ?? '') ?: $options->currency,
                    stock: $stock,
                    oldPriceMinor: $old,
                    barcode: trim((string) $offer->barcode) ?: null,
                    axes: $variantAxes,
                    active: 'false' !== $available || '' !== $stockRaw,
                );
            }
            $pictures = [];
            foreach ($first->picture as $picture) {
                $pictures[] = trim((string) $picture);
            }

            return new ProductImportRow(
                externalId: $externalId,
                names: $names,
                variants: $variants,
                descriptions: $descriptions,
                brand: trim((string) $first->vendor) ?: null,
                categoryPaths: $paths,
                attributes: $attributes,
                mediaUrls: array_values(array_filter($pictures)),
            );
        } catch (InvalidArgumentException $e) {
            return new InvalidImportRowException(\sprintf('offer %s: %s', (string) ($offers[0]['id'] ?? '?'), $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, array{name: string, parent: string|null}> $categories
     *
     * @return list<string>
     */
    private function categoryPath(string $id, array $categories): array
    {
        $path = [];
        $guard = 0;
        for ($current = $id; null !== $current && isset($categories[$current]) && $guard < 20; $current = $categories[$current]['parent'], ++$guard) {
            array_unshift($path, $categories[$current]['name']);
        }

        return $path;
    }
}

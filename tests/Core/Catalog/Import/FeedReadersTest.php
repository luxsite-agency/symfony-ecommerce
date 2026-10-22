<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Import;

use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use App\Core\Catalog\Import\Feed\CsvFeedReader;
use App\Core\Catalog\Import\Feed\FeedOptions;
use App\Core\Catalog\Import\Feed\YmlFeedReader;
use App\Core\Catalog\Import\ProductImportRow;
use PHPUnit\Framework\TestCase;
use XMLReader;

/** Рідери стрімлять рядки: YML групує offer за group_id, CSV — за product_id; зламаний рядок — виняток у потоці. */
final class FeedReadersTest extends TestCase
{
    public function testYmlGroupsOffersIntoVariantsWithAxesAndCategoryPath(): void
    {
        $rows = iterator_to_array((new YmlFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/doors.yml', new FeedOptions()), false);
        self::assertCount(3, $rows);

        $ml14 = $rows[0];
        self::assertInstanceOf(ProductImportRow::class, $ml14);
        self::assertSame('ML14', $ml14->externalId);
        self::assertSame(['uk' => 'Двері ML 14'], $ml14->names);
        self::assertSame('Solo', $ml14->brand);
        self::assertSame([['Двері', 'Міжкімнатні']], $ml14->categoryPaths);
        self::assertSame(['Матеріал' => 'МДФ', 'Висота, мм' => '2000'], $ml14->attributes, 'Колір відрізняється між offer-ами → це вісь, не характеристика');
        self::assertSame(['https://example.test/ml14-white.jpg'], $ml14->mediaUrls);
        self::assertCount(2, $ml14->variants);
        self::assertSame(['ML-14-WHITE', 'ML-14-BLACK'], array_map(static fn ($v): string => $v->sku, $ml14->variants));
        self::assertSame(426_200, $ml14->variants[0]->priceMinor);
        self::assertSame(500_000, $ml14->variants[0]->oldPriceMinor);
        self::assertSame('3.000', $ml14->variants[0]->stock);
        self::assertSame(['Колір' => 'Білий'], $ml14->variants[0]->axes);
        self::assertSame(456_250, $ml14->variants[1]->priceMinor);
        self::assertSame('0.000', $ml14->variants[1]->stock, 'available=false без кількості → 0');
        self::assertFalse($ml14->variants[1]->active);

        $fort = $rows[1];
        self::assertInstanceOf(ProductImportRow::class, $fort);
        self::assertSame('2001', $fort->externalId);
        self::assertSame([['Двері', 'Вхідні']], $fort->categoryPaths);
        self::assertSame(['Матеріал' => 'Метал'], $fort->attributes);
        self::assertSame('1.000', $fort->variants[0]->stock, 'available=true без кількості → 1');

        self::assertInstanceOf(InvalidImportRowException::class, $rows[2]);
        self::assertStringContainsString('3001', $rows[2]->getMessage());
    }

    /** XXE (консиліум безпеки 10.10 п. 1): фід із DOCTYPE/ENTITY відхиляється до парсера — вміст файлу з SYSTEM-сутності в опис не потрапляє. */
    public function testYmlWithDoctypeEntityIsRejected(): void
    {
        $this->expectException(UnsafeFeedException::class);
        $this->expectExceptionMessage('DOCTYPE/ENTITY');
        iterator_to_array((new YmlFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/xxe.yml', new FeedOptions()), false);
    }

    /** Обхід raw-guard коментарем «<!-- <x -->» перед DOCTYPE: ловить вузол DOC_TYPE у циклі читання. */
    public function testYmlWithDoctypeAfterCommentIsRejectedByParserGuard(): void
    {
        $this->expectException(UnsafeFeedException::class);
        $this->expectExceptionMessage('DOCTYPE/ENTITY');
        iterator_to_array((new YmlFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/xxe-comment.yml', new FeedOptions()), false);
    }

    /** Без LIBXML_NOENT парсер сутність не підставляє: навіть якби DOCTYPE пройшов, у описі був би не вміст файлу. */
    public function testParserOptionsDoNotSubstituteExternalEntities(): void
    {
        $reader = new XMLReader();
        self::assertTrue($reader->open(__DIR__.'/../../../Fixtures/feeds/xxe-comment.yml', null, YmlFeedReader::LIBXML_OPTIONS));
        $description = null;
        while (@$reader->read()) {
            if (XMLReader::ELEMENT === $reader->nodeType && 'description' === $reader->localName) {
                $description = $reader->readString();
                break;
            }
        }
        $reader->close();
        self::assertNotNull($description, 'offer прочитано');
        self::assertStringNotContainsString('root:', $description, 'вміст /etc/passwd не підставлено');
        self::assertContains($description, ['', '&secret;'], 'сутність лишається нерозгорнутою або порожньою');
    }

    public function testYmlKeepsSupplierHtmlInDescriptionForApplierToSanitize(): void
    {
        $rows = iterator_to_array((new YmlFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/xss.yml', new FeedOptions()), false);
        self::assertCount(1, $rows);
        self::assertInstanceOf(ProductImportRow::class, $rows[0]);
        self::assertStringContainsString('<strong>МДФ</strong>', (string) $rows[0]->descriptions['uk'], 'рідер віддає HTML як є (CDATA), чистить ProductRowApplier');
    }

    public function testCsvDetectsDelimiterAndGroupsByProductId(): void
    {
        $rows = iterator_to_array((new CsvFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/doors.csv', new FeedOptions()), false);
        self::assertCount(2, $rows);

        $ml14 = $rows[0];
        self::assertInstanceOf(ProductImportRow::class, $ml14);
        self::assertSame('ML14', $ml14->externalId);
        self::assertSame(['uk' => 'Двері ML 14', 'ru' => 'Двери ML 14'], $ml14->names);
        self::assertSame([['Двері', 'Міжкімнатні']], $ml14->categoryPaths);
        self::assertSame(['material' => 'МДФ', 'features' => ['glass', 'lock']], $ml14->attributes);
        self::assertSame(['https://example.test/a.jpg', 'https://example.test/b.jpg'], $ml14->mediaUrls);
        self::assertSame('2026-09-20 12:00:00', $ml14->sourceUpdatedAt?->format('Y-m-d H:i:s'));
        self::assertCount(2, $ml14->variants);
        self::assertSame(['color' => 'Білий'], $ml14->variants[0]->axes);
        self::assertSame(426_200, $ml14->variants[0]->priceMinor);
        self::assertSame(500_000, $ml14->variants[0]->oldPriceMinor);
        self::assertNull($ml14->variants[1]->oldPriceMinor);

        $fort = $rows[1];
        self::assertInstanceOf(ProductImportRow::class, $fort);
        self::assertSame('2001', $fort->externalId, 'Без product_id ключем картки стає external_id');
        self::assertSame([['Двері', 'Вхідні']], $fort->categoryPaths);
        self::assertNull($fort->brand);
    }

    public function testCsvEmptyPriceIsOnRequestAndTranslatedCategoryPath(): void
    {
        $rows = iterator_to_array((new CsvFeedReader())->read(__DIR__.'/../../../Fixtures/feeds/workshop.csv', new FeedOptions()), false);
        self::assertCount(3, $rows);
        [$patch, $custom, $case] = $rows;
        self::assertInstanceOf(ProductImportRow::class, $patch);
        self::assertInstanceOf(ProductImportRow::class, $custom);
        self::assertInstanceOf(ProductImportRow::class, $case);

        self::assertSame(PriceMode::Fixed, $patch->priceMode);
        self::assertSame(['uk' => 'Шеврон Сокіл 77', 'en' => 'Sokil workshop patch'], $patch->names);
        self::assertSame(['en' => [['Patches']]], $patch->categoryTranslations);
        self::assertSame(['https://example.test/p21.jpg', 'https://example.test/p21b.jpg'], $patch->mediaUrls);

        self::assertSame(PriceMode::OnRequest, $custom->priceMode, 'порожня ціна — «за запитом», не 0,00');
        self::assertSame(0, $custom->variants[0]->priceMinor);
        self::assertTrue($custom->variants[0]->active);

        self::assertSame(PriceMode::Fixed, $case->priceMode, 'ціна є хоч в одного варіанта — картка з ціною…');
        self::assertSame([true, false], array_map(static fn ($v): bool => $v->active, $case->variants), '…а варіант без ціни вимкнено');
    }
}

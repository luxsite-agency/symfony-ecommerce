<?php

declare(strict_types=1);

namespace App\Tests\Site\Catalog;

use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogSort;
use App\Site\Catalog\ListingFilterFactory;
use App\Site\Catalog\ListingParams;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListingParamsTest extends TestCase
{
    public function testParsesAndNormalizesToCanonicalQuery(): void
    {
        $params = ListingParams::fromQuery([
            'utm_source' => 'x',
            'page' => '3',
            'sort' => 'price_desc',
            'in_stock' => '1',
            'price' => ['min' => '10', 'max' => ''],
            'brand' => 'puma,nike,nike,BAD SLUG',
            'f' => [
                'size' => 's42,s38,s42,Bad!',
                'weight' => ['min' => '0,5', 'max' => 'abc'],
                'waterproof' => '1',
                '1bad' => 'x',
                'empty' => '',
            ],
        ]);

        self::assertSame([
            'f' => ['size' => 's38,s42', 'waterproof' => '1', 'weight' => ['min' => '0.5']],
            'brand' => 'nike,puma',
            'price' => ['min' => '10'],
            'in_stock' => '1',
            'sort' => 'price_desc',
            'page' => '3',
        ], $params->toQuery());
        self::assertTrue($params->hasFilters());
        self::assertSame(CatalogSort::PriceDesc, $params->sort);
    }

    public function testDefaultsAreOmitted(): void
    {
        $params = ListingParams::fromQuery(['sort' => 'nonsense', 'page' => '1', 'in_stock' => '0']);
        self::assertSame([], $params->toQuery());
        self::assertFalse($params->hasFilters());
    }

    public function testTogglesResetPage(): void
    {
        $params = ListingParams::fromQuery(['f' => ['size' => 's42'], 'page' => '4']);

        self::assertSame(['f' => ['size' => 's38,s42']], $params->toggleValue('size', 's38')->toQuery());
        self::assertSame([], $params->toggleValue('size', 's42')->toQuery(), 'повторний клік знімає значення, порожній атрибут зникає');
        self::assertSame(['f' => ['color' => 'red', 'size' => 's42']], $params->toggleValue('color', 'red')->toQuery());
        self::assertSame(['f' => ['size' => 's42', 'wp' => '0']], $params->toggleFlag('wp', false)->toQuery());
        self::assertSame(['f' => ['size' => 's42']], $params->toggleFlag('wp', true)->toggleFlag('wp', true)->toQuery());
        self::assertSame(['f' => ['size' => 's42'], 'brand' => 'nike'], $params->toggleBrand('nike')->toQuery());
        self::assertSame(['f' => ['size' => 's42'], 'in_stock' => '1'], $params->toggleInStock()->toQuery());
        self::assertSame(['f' => ['size' => 's42'], 'sort' => 'newest'], $params->withSort(CatalogSort::Newest)->toQuery());
        self::assertSame(['f' => ['size' => 's42'], 'page' => '5'], $params->withPage(5)->toQuery());
        self::assertSame(['sort' => 'newest'], $params->withSort(CatalogSort::Newest)->withoutFilters()->toQuery());
    }

    public function testHiddenFieldsForRangeForm(): void
    {
        $params = ListingParams::fromQuery(['f' => ['size' => 's42', 'weight' => ['min' => '1']], 'price' => ['max' => '5'], 'page' => '2']);

        self::assertSame(['f[size]' => 's42', 'price[max]' => '5'], $params->hiddenFields(exceptAttribute: 'weight'));
        self::assertSame(['f[size]' => 's42', 'f[weight][min]' => '1'], $params->hiddenFields(exceptPrice: true));
    }

    public function testLetterIsOneLetterLowercasedAndCountsAsFilter(): void
    {
        $params = ListingParams::fromQuery(['letter' => 'A', 'page' => '3']);
        self::assertSame('a', $params->letter);
        self::assertTrue($params->hasFilters());
        self::assertSame(['letter' => 'a', 'page' => '3'], $params->toQuery());
        self::assertSame('я', ListingParams::fromQuery(['letter' => 'Я'])->letter, 'будь-який алфавіт');
        foreach (['ab', '1', '', '%', ['a']] as $bad) {
            self::assertNull(ListingParams::fromQuery(['letter' => $bad])->letter, var_export($bad, true));
        }
        self::assertSame(['letter' => 'b'], $params->withLetter('b')->toQuery(), 'інша літера — з першої сторінки');
        self::assertSame([], $params->withLetter(null)->toQuery());
        self::assertSame(['in_stock' => '1', 'letter' => 'a'], $params->toggleInStock()->toQuery(), 'решта перемикачів літеру зберігають');
        self::assertSame([], $params->withoutFilters()->toQuery());
    }

    public function testTooManyAttributesIsRejected(): void
    {
        $f = [];
        for ($i = 0; $i <= CatalogFilter::MAX_ATTRIBUTES; ++$i) {
            $f['a'.$i] = 'x';
        }
        $this->expectException(InvalidArgumentException::class);
        ListingParams::fromQuery(['f' => $f]);
    }

    public function testNonNumericPageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ListingParams::fromQuery(['page' => ['1']]);
    }

    public function testPriceConversion(): void
    {
        self::assertSame(1050, ListingFilterFactory::minor('10.5', false));
        self::assertSame(999, ListingFilterFactory::minor('9.999', false), 'нижня межа — вниз');
        self::assertSame(1000, ListingFilterFactory::minor('9.999', true));
        self::assertSame(1000, ListingFilterFactory::minor('9.991', true));
        self::assertSame(0, ListingFilterFactory::minor('-5', false));
        self::assertNull(ListingFilterFactory::minor(null, true));
        self::assertSame('10', ListingFilterFactory::major(1000));
        self::assertSame('10.5', ListingFilterFactory::major(1050));
        self::assertSame('0.05', ListingFilterFactory::major(5));
    }
}

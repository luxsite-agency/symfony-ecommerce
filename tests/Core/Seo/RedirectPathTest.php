<?php

declare(strict_types=1);

namespace App\Tests\Core\Seo;

use App\Core\Seo\RedirectPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectPathTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function sources(): iterable
    {
        yield 'як є' => ['/odyah/futbolky/', '/odyah/futbolky/'];
        yield 'кінцевий слеш зберігається' => ['/tovar', '/tovar'];
        yield 'регістр' => ['/Odyah/FUTBOLKY/', '/odyah/futbolky/'];
        yield 'query і фрагмент' => ['/tovar?utm_source=x&id=1#top', '/tovar'];
        yield 'повний URL' => ['https://old-shop.com.ua/Catalog/Item.html?x=1', '/catalog/item.html'];
        yield 'URL без шляху' => ['https://old-shop.com.ua', '/'];
        yield 'протокол-відносний' => ['//old-shop.com.ua/a', '/a'];
        yield 'без провідного слеша' => ['ru/tovar', '/ru/tovar'];
        yield '«//» на початку — хост, як у браузері' => ['//odyah///futbolky/', '/futbolky/'];
        yield '%-кодування кирилиці' => ['/%D0%9A%D0%B0%D1%82%D0%B0%D0%BB%D0%BE%D0%B3/', '/каталог/'];
        yield 'пробіли довкола' => ["  /tovar \n", '/tovar'];
        yield 'порожньо' => ['', null];
        yield 'лише query' => ['?a=1', null];
        yield 'керівний символ' => ['/a%00b', null];
        yield 'невалідний UTF-8' => ['/%FF%FE', null];
        yield 'задовге' => ['/'.str_repeat('a', RedirectPath::MAX_LENGTH), null];
    }

    #[DataProvider('sources')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, RedirectPath::normalize($input));
    }

    public function testRequestPathWithLeadingDoubleSlashIsNotAUrl(): void
    {
        self::assertSame('/odyah/futbolky/', RedirectPath::fromRequest('//Odyah//futbolky/'));
    }

    public function testRepeatedSlashesInsidePathCollapse(): void
    {
        self::assertSame('/odyah/futbolky/', RedirectPath::normalize('/odyah///futbolky/'));
    }

    public function testTarget(): void
    {
        self::assertSame('/Files/Price.PDF', RedirectPath::normalizeTarget('/Files//Price.PDF'), 'регістр цілі як є (статичні файли), слеші — один');
        self::assertSame('/Search?q=Shoes', RedirectPath::normalizeTarget('/Search?q=Shoes'), 'query цілі зберігається як є');
        self::assertSame('/a', RedirectPath::normalizeTarget('/a#frag?x=1'), 'query після фрагмента — частина фрагмента');
        self::assertNull(RedirectPath::normalizeTarget("/a?x=1\r\nSet-Cookie: s=1"), 'CR/LF у query — ні');
        self::assertNull(RedirectPath::normalizeTarget('/a?x=%0d%0aSet-Cookie'), 'закодовані CR/LF — теж');
        self::assertNull(RedirectPath::normalizeTarget("https://a.example/\nx"));
        self::assertSame('/a', RedirectPath::normalizeTarget('/a?'), 'порожній query — геть');
        self::assertSame('https://Other.example/Path?x=1', RedirectPath::normalizeTarget(' https://Other.example/Path?x=1 '), 'інший сайт — без змін');
        self::assertNull(RedirectPath::normalizeTarget('nova'), 'відносна адреса');
        self::assertNull(RedirectPath::normalizeTarget('//evil.example/x'), 'протокол-відносна — ні');
        self::assertNull(RedirectPath::normalizeTarget('javascript:alert(1)'));
        self::assertNull(RedirectPath::normalizeTarget('https://a.example/with space'));
    }

    public function testTargetPathOnlyForChainableTargets(): void
    {
        self::assertSame('/nova', RedirectPath::targetPath('/nova'));
        self::assertSame('/nova', RedirectPath::targetPath('/Nova'), 'у формі джерела');
        self::assertNull(RedirectPath::targetPath('/search?q=x'));
        self::assertNull(RedirectPath::targetPath('https://other.example/nova'));
    }

    public function testOwnHostTargetBecomesPath(): void
    {
        self::assertSame('/b?x=1', RedirectPath::localize('https://Shop.Example/b?x=1', 'shop.example'));
        self::assertSame('/', RedirectPath::localize('https://www.shop.example', 'shop.example'));
        self::assertSame('https://other.example/b', RedirectPath::localize('https://other.example/b', 'shop.example'));
        self::assertSame('/b', RedirectPath::localize('/b', 'shop.example'));
    }

    public function testEncodeForLocationHeader(): void
    {
        self::assertSame('/%D0%BA%D0%B0%D1%82%D0%B0%D0%BB%D0%BE%D0%B3/a%20b?q=1', RedirectPath::encode('/каталог/a b?q=1'));
        self::assertSame('/odyah/futbolky/', RedirectPath::encode('/odyah/futbolky/'));
    }
}

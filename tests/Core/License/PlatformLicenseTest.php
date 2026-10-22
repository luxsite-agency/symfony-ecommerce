<?php

declare(strict_types=1);

namespace App\Tests\Core\License;

use App\Core\License\PlatformLicense;
use App\Core\Shared\Site\SiteName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class PlatformLicenseTest extends TestCase
{
    public function testDefaultsWithoutConfig(): void
    {
        $license = self::make([]);
        self::assertFalse($license->isConfigured());
        self::assertSame('Магазин', $license->licensee());
        self::assertSame('Store', $license->licensee('en'));
        self::assertSame('Магазин', $license->licensee('ru'), 'назва магазину з фолбеком на мову за замовчуванням');
        self::assertNull($license->type());
        self::assertNull($license->contract());
        self::assertNull($license->since());
        self::assertSame('shop.example.com', $license->siteHost());
        self::assertSame('admin.shop.example.com', $license->adminHost());
    }

    public function testFullConfig(): void
    {
        $license = self::make(['licensee' => ['uk' => 'ФОП Іваненко', 'en' => 'Ivanenko sole proprietor'], 'type' => 'perpetual', 'contract' => '№ 12 від 01.02.2026', 'since' => '2026-02-01']);
        self::assertTrue($license->isConfigured());
        self::assertSame('ФОП Іваненко', $license->licensee('uk'));
        self::assertSame('Ivanenko sole proprietor', $license->licensee('en'));
        self::assertSame('ФОП Іваненко', $license->licensee('ru'), 'мапа без мови → мова за замовчуванням');
        self::assertSame(PlatformLicense::TYPE_PERPETUAL, $license->type());
        self::assertSame('№ 12 від 01.02.2026', $license->contract());
        self::assertSame('2026-02-01', $license->since()?->format('Y-m-d'));
    }

    public function testLicenseeAsStringAndEmptyValuesMeanDefaults(): void
    {
        $license = self::make(['licensee' => ' ТОВ «Назва» ', 'type' => '', 'contract' => null, 'since' => '']);
        self::assertTrue($license->isConfigured());
        self::assertSame('ТОВ «Назва»', $license->licensee('en'));
        self::assertNull($license->type());
        self::assertNull($license->since());
        self::assertSame('Store', self::make(['licensee' => ['uk' => '']])->licensee('en'), 'порожня мапа → назва магазину');
    }

    public function testTextLocaleFallsBackToEnglish(): void
    {
        $license = self::make([]);
        self::assertSame('uk', $license->textLocale('uk'));
        self::assertSame('en', $license->textLocale('en'));
        self::assertSame('en', $license->textLocale('ru'));
        self::assertSame('license/text.en.html.twig', $license->textTemplate('pl'));
        self::assertSame('license/text.uk.html.twig', $license->textTemplate('uk'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'невідомий ключ' => [['licencee' => 'x'], 'невідомі ключі licencee'];
        yield 'тип' => [['type' => 'rental'], 'app.license.type'];
        yield 'дата не за форматом' => [['since' => '01.02.2026'], 'app.license.since'];
        yield 'дата неіснуюча' => [['since' => '2026-02-30'], 'app.license.since'];
        yield 'власник не рядок' => [['licensee' => 5], 'app.license.licensee'];
        yield 'власник — мапа з не-рядками' => [['licensee' => ['uk' => ['x']]], 'app.license.licensee'];
        yield 'договір — масив' => [['contract' => ['a']], 'app.license.contract'];
    }

    /** @param array<string, mixed> $config */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRejected(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        self::make($config);
    }

    /** @param array<string, mixed> $config */
    private static function make(array $config): PlatformLicense
    {
        return new PlatformLicense($config, new SiteName(['uk' => 'Магазин', 'en' => 'Store'], 'uk', new RequestStack()), 'shop.example.com', 'admin.shop.example.com', 'uk');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared\Text;

use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Text\Slug;
use App\Core\Shared\Translation\AbstractTranslation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextRulesTest extends TestCase
{
    public function testValidValuesPassTrimmed(): void
    {
        self::assertSame('dveri-mizhkimnatni-ml-14', Slug::assertValid(' dveri-mizhkimnatni-ml-14 '));
        self::assertSame('shipping_weight', MachineCode::assertValid('shipping_weight'));
    }

    /** @return iterable<string, array{string}> */
    public static function badSlugs(): iterable
    {
        yield 'великі літери' => ['Dveri'];
        yield 'кирилиця' => ['двері'];
        yield 'подвійний дефіс' => ['a--b'];
        yield 'дефіс на краю' => ['-a'];
        yield 'пробіл' => ['a b'];
    }

    #[DataProvider('badSlugs')]
    public function testSlugRejects(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);
        Slug::assertValid($slug);
    }

    public function testMachineCodeMustStartWithLetter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MachineCode::assertValid('1size');
    }

    public function testLocaleNormalization(): void
    {
        self::assertSame('uk', AbstractTranslation::normalizeLocale('UK'));
        self::assertSame('en_US', AbstractTranslation::normalizeLocale('en-us'));
        $this->expectException(InvalidArgumentException::class);
        AbstractTranslation::normalizeLocale('ukrainian');
    }
}

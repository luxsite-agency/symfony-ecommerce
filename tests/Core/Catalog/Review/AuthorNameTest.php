<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Review;

use App\Core\Catalog\Review\AuthorName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Підпис «Ім'я П.»: без прізвища й email. */
final class AuthorNameTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'ім’я прізвище' => ['Олена Демо', 'Олена Д.'];
        yield 'три слова' => ['Іван Петрович Коваль', 'Іван П.'];
        yield 'одне слово' => ['Олена', 'Олена'];
        yield 'зайві пробіли' => ['  Олена   Демо ', 'Олена Д.'];
        yield 'подвійне прізвище' => ['Марія Петренко-Іваненко', 'Марія П.-І.'];
        yield 'латиниця мала' => ['john smith', 'john S.'];
        yield 'порожнє' => ['', ''];
    }

    #[DataProvider('names')]
    public function testShort(string $name, string $expected): void
    {
        self::assertSame($expected, AuthorName::short($name));
    }
}

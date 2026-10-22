<?php

declare(strict_types=1);

namespace App\Tests\Unit\Site\Account\Google;

use App\Site\Account\Google\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnPathTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function paths(): iterable
    {
        yield 'відносний' => ['/cart/', '/cart/'];
        yield 'з мовою і query' => ['/ru/dveri/?page=2', '/ru/dveri/?page=2'];
        yield 'товар' => ['/door-95-066', '/door-95-066'];
        yield 'порожньо' => ['', null];
        yield 'null' => [null, null];
        yield 'абсолютна адреса' => ['https://evil.example/', null];
        yield 'protocol-relative' => ['//evil.example/', null];
        yield 'backslash' => ['/\\evil.example', null];
        yield 'керівні символи' => ["/cart/\r\nLocation: x", null];
        yield 'без слеша' => ['cart/', null];
        yield 'старт Google (цикл)' => ['/account/google/start', null];
        yield 'callback Google з мовою' => ['/ru/account/google/callback?state=x', null];
        yield 'вихід' => ['/account/logout', null];
        yield 'кабінет дозволений' => ['/account/orders', '/account/orders'];
        yield 'задовгий' => ['/'.str_repeat('a', ReturnPath::MAX), null];
    }

    #[DataProvider('paths')]
    public function testSanitize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, ReturnPath::sanitize($input));
    }
}

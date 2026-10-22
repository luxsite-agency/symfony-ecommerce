<?php

declare(strict_types=1);

namespace App\Tests\Core\Customer;

use App\Core\Customer\Http\AccountPath;
use PHPUnit\Framework\TestCase;

/** Шляхи кабінету (повторне рев'ю безпеки #45, п. A і C): без аналітики, без запису в журнал 404, referer лише origin. */
final class AccountPathTest extends TestCase
{
    public function testMatchesAccountPathsWithAndWithoutLocale(): void
    {
        foreach (['/account', '/account/', '/account/verify/abc', '/ru/account/password/x', '/en_US/account/login', '/ACCOUNT/verify/x', '/%61ccount/verify/x'] as $path) {
            self::assertTrue(AccountPath::matches($path), $path);
        }
        foreach (['/', '/accounts', '/my-account', '/blog/account', '/ru/accounting', '/cart/account'] as $path) {
            self::assertFalse(AccountPath::matches($path), $path);
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Core\Infra;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Страж правила nginx для форми заявки /request у всіх трьох конфігах: окремий location з тілом ≤ 11m (файл 10 МБ + поля),
 * щоб завеликий файл відсікався до PHP, і той самий try_files, що й у location /. Синтаксис — nginx -t у tests/store/run.sh.
 */
final class NginxLeadFormRuleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function configs(): iterable
    {
        yield 'prod' => ['prod.conf'];
        yield 'store' => ['store.conf'];
        yield 'dev' => ['default.conf'];
    }

    #[DataProvider('configs')]
    public function testRequestLocationLimitsBody(string $file): void
    {
        $conf = (string) file_get_contents(\dirname(__DIR__, 3).'/docker/nginx/'.$file);
        self::assertSame(1, preg_match('#location = /request \{(.*?)\n    \}#s', $conf, $m), $file.': location = /request');
        self::assertStringContainsString('client_max_body_size 11m;', $m[1], $file);
        self::assertStringContainsString('try_files $uri /index.php$is_args$args;', $m[1], $file);
        self::assertLessThan(strpos($conf, 'location / {') ?: \PHP_INT_MAX, strpos($conf, 'location = /request'), $file.': перед location /');
    }
}

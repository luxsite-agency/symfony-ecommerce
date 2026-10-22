<?php

declare(strict_types=1);

namespace App\Tests\Core\Infra;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Страж правил nginx для /api/admin і /mcp (E15 PR-6) у всіх трьох конфігах: location з тим самим шаблоном, що firewall
 * (ApiPaths::PATTERN), 404 поза хостом адмінки, тіло ≤ 1m, limit_req з IP, формат журналу без URI/query. Синтаксис і поведінку
 * перевіряє `nginx -t` у tests/store/run.sh (CI store-ops); тут — щоб правило не загубилось при правці одного з файлів.
 */
final class NginxApiRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function configs(): iterable
    {
        yield 'prod' => ['prod.conf', '"${SHOP_ADMIN_HOST}"'];
        yield 'store' => ['store.conf', '"${STORE_ADMIN_HOST}"'];
        yield 'dev' => ['default.conf', 'shop-engine-admin.localhost'];
    }

    #[DataProvider('configs')]
    public function testApiLocationIsHardened(string $file, string $adminHost): void
    {
        $conf = (string) file_get_contents(\dirname(__DIR__, 3).'/docker/nginx/'.$file);
        self::assertStringContainsString('map $host $api_host_ok { default 0; '.$adminHost.' 1; }', $conf, 'хост адмінки');
        self::assertSame(1, preg_match('#location ~ \^/\(api/admin\|mcp\)\(/\|\$\) \{(.*?)\n    \}#s', $conf, $m), 'location API з шаблоном firewall');
        $block = $m[1];
        foreach (['if ($api_host_ok = 0) { return 404; }', 'client_max_body_size 1m;', 'limit_req zone=shop_api burst=30 nodelay;', 'limit_req_status 429;', 'error_page 413 @api_too_large;', 'error_page 429 @api_too_many;', 'try_files $uri /index.php$is_args$args;'] as $line) {
            self::assertStringContainsString($line, $block, $file.': '.$line);
        }
        self::assertStringContainsString('limit_req_zone $binary_remote_addr zone=shop_api:10m rate=10r/s;', $conf);
        // журнал: повний формат main вимкнено для API, короткий shop_api — без $request/$request_uri/$args (там SKU і текст пошуку)
        self::assertSame(1, preg_match('#log_format shop_api \'([^\']*)\'#', $conf, $f));
        self::assertSame(0, preg_match('/\$(request|request_uri|args|query_string|uri|http_\w+)\b/', $f[1]), $file.': формат shop_api без рядка запиту, URI, query і заголовків');
        // (/|\?|$): $request_uri містить query, тож /mcp?x=1 теж має піти в shop_api, а не в main (рев'ю C #84 L1)
        self::assertStringContainsString('~^/(api/admin|mcp)(/|\\?|$) 0;', $conf, 'main-лог вимкнено для API (і з query)');
        self::assertStringContainsString('map $request_uri $api_kind { default ""; ~^/mcp(/|\\?|$) mcp; ~^/api/admin(/|\\?|$) api_admin; }', $conf, 'api_kind і з query');
        self::assertStringContainsString('access_log /var/log/nginx/access.log shop_api if=$api_kind;', $conf);
        // відмови — JSON у словнику API
        self::assertStringContainsString('return 413 \'{"error":"payload_too_large"', $conf);
        self::assertStringContainsString('return 429 \'{"error":"too_many_requests"', $conf);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Import;

use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use App\Core\Catalog\Import\Feed\FeedDownloader;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Завантаження фіду за URL (консиліум безпеки 10.10 п. 3): лише http(s), без приватних мереж, з лімітом розміру; тимчасовий файл не лишається при помилці. */
final class FeedDownloaderTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    public function testDownloadsBodyToTemporaryFile(): void
    {
        $downloader = $this->downloader(['https://example.com/feed.yml' => new MockResponse('<yml_catalog/>')], 1024);
        $path = $downloader->download('https://example.com/feed.yml');
        try {
            self::assertFileExists($path);
            self::assertSame('<yml_catalog/>', file_get_contents($path));
            self::assertSame(['https://example.com/feed.yml'], $this->requested);
        } finally {
            @unlink($path);
        }
    }

    public function testDeclaredContentLengthOverLimitIsRejectedBeforeReading(): void
    {
        $downloader = $this->downloader(['https://example.com/big.yml' => new MockResponse(str_repeat('x', 2048), ['response_headers' => ['content-length' => '2048']])], 1024);
        $before = self::tempFeedFiles();
        try {
            $downloader->download('https://example.com/big.yml');
            self::fail('Очікувалась відмова за Content-Length');
        } catch (UnsafeFeedException $e) {
            self::assertStringContainsString('Content-Length 2048', $e->getMessage());
        }
        self::assertSame($before, self::tempFeedFiles(), 'тимчасовий файл видалено');
    }

    public function testStreamOverLimitIsCutAndTemporaryFileRemoved(): void
    {
        $body = static function (): Generator {
            for ($i = 0; $i < 10; ++$i) {
                yield str_repeat('a', 100);
            }
        };
        $downloader = $this->downloader(['https://example.com/endless.yml' => new MockResponse($body())], 250);
        $before = self::tempFeedFiles();
        try {
            $downloader->download('https://example.com/endless.yml');
            self::fail('Очікувалась відмова за розміром потоку');
        } catch (UnsafeFeedException $e) {
            self::assertStringContainsString('понад ліміт 250 байт', $e->getMessage());
        }
        self::assertSame($before, self::tempFeedFiles(), 'тимчасовий файл видалено, /tmp не росте');
    }

    public function testPrivateNetworkIsBlocked(): void
    {
        $downloader = $this->downloader(['http://127.0.0.1/feed.yml' => new MockResponse('<yml_catalog/>')], 1024);
        try {
            $downloader->download('http://127.0.0.1/feed.yml');
            self::fail('Очікувалась заборона приватної адреси');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('не завантажено', $e->getMessage());
        }
    }

    public function testOnlyHttpSchemesAreAllowed(): void
    {
        $downloader = $this->downloader([], 1024);
        $this->expectException(UnsafeFeedException::class);
        $downloader->download('ftp://example.com/feed.yml');
    }

    public function testHttpErrorIsReported(): void
    {
        $downloader = $this->downloader(['https://example.com/missing.yml' => new MockResponse('', ['http_code' => 404])], 1024);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');
        $downloader->download('https://example.com/missing.yml');
    }

    public function testHasSchemeDistinguishesUrlsFromPaths(): void
    {
        self::assertTrue(FeedDownloader::hasScheme('https://example.com/f.yml'));
        self::assertTrue(FeedDownloader::hasScheme('ftp://example.com/f.yml'));
        self::assertTrue(FeedDownloader::hasScheme('php://filter/resource=/etc/passwd'));
        self::assertTrue(FeedDownloader::hasScheme('data:text/plain,x'));
        self::assertFalse(FeedDownloader::hasScheme('/var/feeds/f.yml'));
        self::assertFalse(FeedDownloader::hasScheme('feeds/f.yml'));
        self::assertFalse(FeedDownloader::hasScheme('C:\\feeds\\f.yml'));
    }

    /** @param array<string, MockResponse> $responses */
    private function downloader(array $responses, int $maxBytes): FeedDownloader
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requested[] = $url;

            return $responses[$url] ?? new MockResponse('', ['http_code' => 500]);
        });

        return new FeedDownloader($client, $maxBytes);
    }

    /** @return list<string> */
    private static function tempFeedFiles(): array
    {
        $files = glob(sys_get_temp_dir().\DIRECTORY_SEPARATOR.'feed-*');

        return false === $files ? [] : $files;
    }
}

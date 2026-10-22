<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Import;

use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\MediaSource;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Import\Media\MediaImporter;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Хости — example.com/example.org (IANA, публічні IP): NoPrivateNetworkHttpClient резолвить DNS і блокує .test. Зображення з фіду: лише http(s) і справжні зображення, межа розміру, дедуплікація за URL і вмістом, без дублів у картці. */
final class MediaImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDownloadsOnceLinksInOrderAndDedupesByUrlAndContent(): void
    {
        $red = self::png(255, 0, 0);
        $importer = $this->importer([
            'https://example.com/a.png' => new MockResponse($red, ['response_headers' => ['content-type' => 'image/png']]),
            'https://example.com/b.png' => new MockResponse(self::png(0, 0, 255)),
            'https://www.example.org/a-copy.png' => new MockResponse($red), // той самий вміст під іншим URL
            'https://example.com/page.html' => new MockResponse('<html>not an image</html>', ['response_headers' => ['content-type' => 'image/png']]),
            'https://example.com/huge.png' => new MockResponse((static function (): Generator {
                for ($i = 0; $i <= MediaImporter::MAX_BYTES / 1048576; ++$i) {
                    yield str_repeat('x', 1048576); // без Content-Length — межа спрацьовує на потоці
                }
            })()),
            'https://example.com/404.png' => new MockResponse('', ['http_code' => 404]),
        ]);
        $product = $this->product();

        $result = $importer->importForProduct((int) $product->getId(), [
            'https://example.com/a.png', 'https://example.com/b.png', 'https://www.example.org/a-copy.png',
            'https://example.com/page.html', 'https://example.com/huge.png', 'https://example.com/404.png', 'ftp://example.com/x.png', 'file:///etc/passwd',
        ]);
        self::assertSame(2, $result['downloaded'], json_encode($result['failed'], \JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $result['reused'], 'a-copy — той самий вміст, що й a');
        self::assertSame(2, $result['linked'], 'у картці — два різні файли');
        self::assertSame(['https://example.com/page.html', 'https://example.com/huge.png', 'https://example.com/404.png', 'ftp://example.com/x.png', 'file:///etc/passwd'], array_keys($result['failed']));
        self::assertStringContainsString('Не зображення', $result['failed']['https://example.com/page.html']);
        self::assertStringContainsString('10 МБ', $result['failed']['https://example.com/huge.png']);
        self::assertStringContainsString('http(s)', $result['failed']['file:///etc/passwd']);
        self::assertNotContains('ftp://example.com/x.png', $this->requested, 'не-http схеми навіть не запитуються');

        $this->em->clear();
        $product = $this->em->find(Product::class, $product->getId());
        self::assertNotNull($product);
        $media = array_map(static fn ($l): Media => $l->getMedia(), $product->getMedia()->toArray());
        self::assertCount(2, $media);
        self::assertSame(['image/png', 'image/png'], array_map(static fn (Media $m): string => $m->getMimeType(), $media));
        self::assertSame([4, 3], [$media[0]->getWidth(), $media[0]->getHeight()]);
        self::assertSame(hash('sha256', $red), $media[0]->getContentHash());
        self::assertSame($media[0], $this->em->find(MediaSource::class, MediaSource::hash('https://www.example.org/a-copy.png'))?->getMedia());

        // повторний імпорт: нічого не качається й не дублюється
        $this->requested = [];
        $again = $this->importer([])->importForProduct((int) $product->getId(), ['https://example.com/a.png', 'https://example.com/b.png', 'https://www.example.org/a-copy.png']);
        self::assertSame(['linked' => 0, 'downloaded' => 0, 'reused' => 3, 'failed' => []], $again);
        self::assertSame([], $this->requested);
    }

    public function testPrivateNetworkIsRefused(): void
    {
        $result = $this->importer(['http://127.0.0.1/a.png' => new MockResponse(self::png(1, 2, 3))])->importForProduct((int) $this->product()->getId(), ['http://127.0.0.1/a.png', 'http://169.254.169.254/latest/meta-data']);
        self::assertSame(0, $result['linked']);
        self::assertCount(2, $result['failed']);
    }

    /** @param array<string, MockResponse> $responses */
    private function importer(array $responses): MediaImporter
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requested[] = $url;

            return $responses[$url] ?? new MockResponse('', ['http_code' => 500]);
        });

        return new MediaImporter($this->em, $client, new NullLogger());
    }

    private function product(): Product
    {
        $product = new Product('MI-'.bin2hex(random_bytes(3)), 'UAH');
        $product->translate('uk')->setName('Шеврон')->setSlug('mi-'.bin2hex(random_bytes(3)));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private static function png(int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor(4, 3);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

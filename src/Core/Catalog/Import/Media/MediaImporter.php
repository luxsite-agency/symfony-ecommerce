<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Media;

use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\MediaSource;
use App\Core\Catalog\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Зображення з фіду імпорту (колонка images / <picture>) → медіатека і картка товару, у порядку фіду.
 *
 * Безпека й межі: лише http(s); приватні й службові мережі заборонені (NoPrivateNetworkHttpClient — фід не має
 * змусити сервер качати з localhost/метаданих хмари); таймаут з'єднання/простою 10 с, увесь запит ≤ 30 с, ≤ 3 редиректи;
 * розмір ≤ 10 МБ (Content-Length і фактичний потік); тип — за вмістом (finfo), лише JPEG/PNG/WebP/GIF, і файл має
 * читатися як зображення.
 *
 * Дедуплікація: той самий URL — не качається вдруге (MediaSource); інший URL з тим самим вмістом (sha256) — той самий
 * Media. Уже прив'язаний до товару файл не прив'язується вдруге, тож повторний імпорт нічого не дублює. Зображення, яких
 * більше нема у фіді, не відв'язуються (їх могли додати в адмінці).
 */
final class MediaImporter
{
    public const int MAX_BYTES = 10 * 1024 * 1024;
    public const int MAX_URLS = 20;
    private const array MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly EntityManagerInterface $em,
        HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale = 'uk',
    ) {
        $this->http = $http instanceof NoPrivateNetworkHttpClient ? $http : new NoPrivateNetworkHttpClient($http);
    }

    /**
     * @param list<string> $urls
     *
     * @return array{linked: int, downloaded: int, reused: int, failed: array<string, string>} failed — URL → причина
     */
    public function importForProduct(int $productId, array $urls): array
    {
        $result = ['linked' => 0, 'downloaded' => 0, 'reused' => 0, 'failed' => []];
        $product = $this->em->find(Product::class, $productId);
        if (null === $product) {
            return $result;
        }
        $linked = [];
        foreach ($product->getMedia() as $link) {
            $linked[spl_object_id($link->getMedia())] = true;
        }

        foreach (\array_slice(array_values(array_unique(array_map(trim(...), $urls))), 0, self::MAX_URLS) as $url) {
            try {
                // alt — назва мовою магазину за замовчуванням (Product::__toString бере перший-ліпший переклад)
                [$media, $downloaded] = $this->resolve($url, $product->getTranslation($this->defaultLocale)?->getName() ?? (string) $product);
                $downloaded ? ++$result['downloaded'] : ++$result['reused'];
                if (!isset($linked[spl_object_id($media)])) {
                    $product->addMedia($media);
                    $linked[spl_object_id($media)] = true;
                    ++$result['linked'];
                }
                $this->em->flush();
            } catch (Throwable $e) {
                $result['failed'][$url] = $e->getMessage();
                $this->logger->warning('Імпорт зображення: пропущено', ['product' => $productId, 'url' => $url, 'error' => $e->getMessage()]);
                if (!$this->em->isOpen()) {
                    throw $e;
                }
            }
        }

        return $result;
    }

    /**
     * Одне зображення за URL у медіатеку (обкладинка запису від зовнішньої контент-панелі тощо) з тими самими перевірками й
     * дедуплікацією, що й для фіду. Media і MediaSource лише persist — flush робить той, хто викликає.
     *
     * @throws RuntimeException URL не http(s), приватна мережа, не зображення, завеликий файл, HTTP-помилка
     */
    public function importUrl(string $url, string $alt): Media
    {
        return $this->resolve(trim($url), $alt)[0];
    }

    /** @return array{Media, bool} Media і чи файл щойно завантажено (false — уже був за URL або за вмістом) */
    private function resolve(string $url, string $alt): array
    {
        self::assertUrl($url);
        $source = $this->em->find(MediaSource::class, MediaSource::hash($url));
        if (null !== $source) {
            return [$source->getMedia(), false];
        }
        [$media, $downloaded] = $this->download($url, $alt);
        $this->em->persist(new MediaSource($url, $media));

        return [$media, $downloaded];
    }

    private static function assertUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true) || '' === (string) parse_url($url, \PHP_URL_HOST) || \strlen($url) > 2048) {
            throw new RuntimeException('Дозволені лише http(s)-адреси');
        }
    }

    /** @return array{Media, bool} Media і чи він новий (false — знайдено за хешем вмісту) */
    private function download(string $url, string $alt): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media-');
        if (false === $tmp) {
            throw new RuntimeException('Не вдалося створити тимчасовий файл');
        }
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 30,
                'max_redirects' => 3,
                'headers' => ['Accept' => 'image/*', 'User-Agent' => 'shop-engine media import'],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(\sprintf('HTTP %d', $response->getStatusCode()));
            }
            $declared = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if ($declared > self::MAX_BYTES) {
                throw new RuntimeException(\sprintf('Файл більший за %d МБ', self::MAX_BYTES / 1048576));
            }
            $handle = fopen($tmp, 'w');
            if (false === $handle) {
                throw new RuntimeException('Не вдалося записати тимчасовий файл');
            }
            $size = 0;
            try {
                foreach ($this->http->stream($response) as $chunk) {
                    $content = $chunk->getContent();
                    $size += \strlen($content);
                    if ($size > self::MAX_BYTES) {
                        $response->cancel();
                        throw new RuntimeException(\sprintf('Файл більший за %d МБ', self::MAX_BYTES / 1048576));
                    }
                    fwrite($handle, $content);
                }
            } finally {
                fclose($handle);
            }
        } catch (HttpException $e) {
            @unlink($tmp);
            throw new RuntimeException('Не вдалося завантажити: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        $mime = (string) (new finfo(\FILEINFO_MIME_TYPE))->file($tmp);
        $dimensions = @getimagesize($tmp);
        if (!isset(self::MIME_EXTENSIONS[$mime]) || false === $dimensions) {
            @unlink($tmp);
            throw new RuntimeException(\sprintf('Не зображення JPEG/PNG/WebP/GIF (%s)', $mime ?: '?'));
        }
        $hash = (string) hash_file('sha256', $tmp);
        $existing = $this->em->getRepository(Media::class)->findOneBy(['contentHash' => $hash]);
        if (null !== $existing) {
            @unlink($tmp);

            return [$existing, false];
        }

        $media = new Media('pending-'.bin2hex(random_bytes(6)), $mime, $size);
        $media->setFile(new UploadedFile($tmp, 'import.'.self::MIME_EXTENSIONS[$mime], $mime, null, true)); // Vich переносить у public/media
        $media->setDimensions($dimensions[0], $dimensions[1]);
        $media->setContentHash($hash);
        $media->setAlt(mb_substr($alt, 0, 255));
        $this->em->persist($media);

        return [$media, true];
    }
}

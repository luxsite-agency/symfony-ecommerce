<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

use App\Core\Catalog\Entity\ImportRun;
use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use App\Core\Catalog\Import\ProductImporter;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Адаптер фідів постачальників (ADR D10, Rev 3): файл або URL → рідер за форматом → ProductImporter.
 * URL (лише http/https, FeedDownloader: без приватних мереж, з лімітом розміру) стрімиться на диск у тимчасовий файл
 * (не в пам'ять), далі читається як локальний. Інші схеми (ftp://, php://, data:) не приймаються.
 */
final class FeedImportAdapter
{
    /** @var array<string, FeedReaderInterface> */
    private array $readers = [];

    /** @param iterable<FeedReaderInterface> $readers */
    public function __construct(
        #[AutowireIterator('app.feed_reader')] iterable $readers,
        private readonly ProductImporter $importer,
        private readonly FeedDownloader $downloader,
    ) {
        foreach ($readers as $reader) {
            $this->readers[$reader->getFormat()] = $reader;
        }
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_keys($this->readers);
    }

    public function import(string $pathOrUrl, string $source, FeedOptions $options = new FeedOptions(), ?string $format = null, int $chunkSize = 500, bool $sync = false): ImportRun
    {
        $temp = null;
        $path = $pathOrUrl;
        if (FeedDownloader::hasScheme($pathOrUrl)) {
            if (1 !== preg_match('#^https?://#i', $pathOrUrl)) {
                throw new UnsafeFeedException(\sprintf('Фід %s: дозволено лише http(s) або шлях до локального файлу', $pathOrUrl));
            }
            $temp = $this->downloader->download($pathOrUrl);
            $path = $temp;
        }
        try {
            $reader = $this->reader($path, $format);

            return $this->importer->import($source, $reader->read($path, $options), $chunkSize, $sync);
        } finally {
            if (null !== $temp) {
                @unlink($temp);
            }
        }
    }

    public function reader(string $path, ?string $format): FeedReaderInterface
    {
        if (null !== $format && '' !== $format && 'auto' !== $format) {
            return $this->readers[$format] ?? throw new InvalidArgumentException(\sprintf('Невідомий формат "%s"; доступні: %s', $format, implode(', ', array_keys($this->readers))));
        }
        foreach ($this->readers as $reader) {
            if ($reader->supports($path)) {
                return $reader;
            }
        }
        throw new InvalidArgumentException(\sprintf('Формат фіду не розпізнано: %s (вкажіть --format)', $path));
    }
}

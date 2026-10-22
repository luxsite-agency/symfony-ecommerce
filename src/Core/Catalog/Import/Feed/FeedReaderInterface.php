<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\ProductImportRow;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Рідер фіду постачальника: стрімить рядки з локального файлу, не збираючи весь файл у пам'яті. */
#[AutoconfigureTag('app.feed_reader')]
interface FeedReaderInterface
{
    /** Код формату для --format (yml, csv). */
    public function getFormat(): string;

    /** Чи береться за файл (за розширенням/першими байтами), коли формат не задано явно. */
    public function supports(string $path): bool;

    /**
     * @return iterable<ProductImportRow|InvalidImportRowException> невалідний рядок віддається винятком, читання триває
     */
    public function read(string $path, FeedOptions $options): iterable;
}

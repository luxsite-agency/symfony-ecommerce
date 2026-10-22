<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url\MessageHandler;

use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Url\CategoryPaths;
use App\Core\Catalog\Url\Message\RebuildCategoryPaths;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RebuildCategoryPathsHandler
{
    public function __construct(
        private readonly CategoryPaths $paths,
        private readonly CatalogCacheVersion $cacheVersion,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RebuildCategoryPaths $message): void
    {
        $changed = $this->paths->rebuild($message->categoryIds);
        $this->paths->syncManaged($this->em); // у sync-обробці (тести, CLI) — ті самі сутності, що й у викликача
        if ($changed > 0) {
            $this->cacheVersion->bump(); // кеш адрес (SiteUrlResolver) і навігації — у versioned namespace
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed\MessageHandler;

use App\Core\Catalog\Import\Message\ImportCompleted;
use App\Site\Feed\MerchantFeeds;
use App\Site\Feed\Message\GenerateMerchantFeeds;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Фід — за розкладом (GenerateMerchantFeeds з App\Schedule) і після кожного імпорту: ImportCompleted (async) лише ставить
 * GenerateMerchantFeeds у чергу feeds, генерує окремий воркер (хвилини на 500k карток не тримають листи й CRM).
 */
final class GenerateMerchantFeedsHandler
{
    public function __construct(private readonly MerchantFeeds $feeds, private readonly MessageBusInterface $bus)
    {
    }

    #[AsMessageHandler]
    public function onGenerate(GenerateMerchantFeeds $message): void
    {
        $this->feeds->generate();
    }

    #[AsMessageHandler]
    public function onImport(ImportCompleted $message): void
    {
        $this->bus->dispatch(new GenerateMerchantFeeds());
    }
}

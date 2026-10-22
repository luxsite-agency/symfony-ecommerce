<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Site\Feed\FeedChannel;
use App\Site\Feed\MerchantFeedPaths;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Віддає згенерований фід (feed:merchant:generate): Google Merchant і Meta. На проді nginx знаходить файл у public/feeds/
 * сам (try_files), сюди запит доходить, коли файлу ще нема (404) або статику не віддає вебсервер. Фід не генерується в
 * запиті: на 500k — хвилини.
 */
final class MerchantFeedController extends AbstractController
{
    private const string LOCALE = '[a-z]{2}(?:[-_][A-Za-z]{2})?';

    public function __construct(private readonly MerchantFeedPaths $paths)
    {
    }

    #[Route('/feeds/google-merchant-{locale}.xml', name: 'site_merchant_feed', requirements: ['locale' => self::LOCALE], defaults: ['channel' => 'google'], methods: ['GET', 'HEAD'])]
    #[Route('/feeds/meta-{locale}.xml', name: 'site_meta_feed', requirements: ['locale' => self::LOCALE], defaults: ['channel' => 'meta'], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $locale, string $channel): BinaryFileResponse
    {
        $feed = FeedChannel::tryFrom($channel);
        if (null === $feed || !\in_array($locale, $this->paths->locales(), true) || !is_file($file = $this->paths->file($locale, $feed))) {
            throw $this->createNotFoundException('Фіду нема');
        }
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'application/xml; charset=UTF-8'], true, null, true, true);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}

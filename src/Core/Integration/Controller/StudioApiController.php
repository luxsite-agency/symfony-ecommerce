<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Controller;

use App\Core\Integration\Studio\StudioApiGuard;
use App\Core\Integration\Studio\StudioArticlePayload;
use App\Core\Integration\Studio\StudioArticlePublisher;
use App\Core\Integration\Studio\StudioPageIndex;
use App\Core\Integration\Studio\StudioPayloadException;
use App\Core\Shared\Site\SiteName;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Приймач зовнішньої контент-панелі (AI-панель агенції, окремий сервіс) — той самий контракт, що в інших сайтах LuxSite:
 *
 *   GET /api/studio/ping                    — перевірка токена: {ok, site, name, langs}
 *   PUT /api/studio/articles/{externalId}   — upsert статті в блог за group_id і мовою: {id, url, external_id, created}
 *   GET /api/studio/pages                   — сторінки магазину для перелінковки: {site, count, pages[]}
 *
 * Авторизація й ліміт — StudioApiGuard (Bearer STUDIO_API_TOKEN). Лише на хості сайту: на admin_host маршрутів нема.
 */
#[Route('/api/studio', name: StudioApiGuard::ROUTE_PREFIX, condition: "request.getHost() !== '%admin_host%'", stateless: true, format: 'json')]
final class StudioApiController extends AbstractController
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly StudioArticlePublisher $publisher,
        private readonly StudioPageIndex $pageIndex,
        private readonly SiteName $siteName,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json(['ok' => true, 'site' => $this->siteHost, 'name' => $this->siteName->forLocale($this->defaultLocale), 'langs' => $this->locales]);
    }

    #[Route('/articles/{externalId}', name: 'article_put', requirements: ['externalId' => '[A-Za-z0-9_-]{1,64}'], methods: ['PUT'])]
    public function put(string $externalId, Request $request, #[MapRequestPayload(acceptFormat: 'json')] StudioArticlePayload $payload): JsonResponse
    {
        try {
            $result = $this->publisher->publish($payload, $request->getHost());
        } catch (StudioPayloadException $e) {
            return $this->json(['error' => $e->getMessage(), 'fields' => $e->fields], $e->statusCode);
        }

        return $this->json(
            ['id' => (string) $result->postId, 'url' => $result->url, 'external_id' => $externalId, 'created' => $result->created] + ([] === $result->warnings ? [] : ['warnings' => $result->warnings]),
            $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route('/pages', name: 'pages', methods: ['GET'])]
    public function pages(): JsonResponse
    {
        $pages = $this->pageIndex->pages();

        return $this->json(['site' => $this->siteHost, 'count' => \count($pages), 'pages' => $pages]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Live;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Живий theme.css: var/share/theme/public/theme.css віддає PHP, бо nginx магазину не бачить тома share (у service-engine —
 * симлінк public/theme-live → shared/theme/public). Адреса з ?v=<хеш вмісту> (site_theme_live_css) кешується рік, без версії —
 * хвилину. Файлу нема — 404 (base.html.twig тоді й не підключає).
 */
final class LiveCssController extends AbstractController
{
    public function __construct(private readonly LiveTheme $theme)
    {
    }

    #[Route(LiveTheme::CSS_URL, name: 'site_theme_live_css', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $file = $this->theme->cssFile();
        if (!is_file($file) || is_link($file)) {
            throw $this->createNotFoundException();
        }
        $response = new Response((string) file_get_contents($file), Response::HTTP_OK, ['Content-Type' => 'text/css; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge('' === (string) $request->query->get('v', '') ? 60 : 31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}

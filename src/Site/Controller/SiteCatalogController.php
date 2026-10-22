<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Site\Routing\SiteRouteLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catch-all каталогу (маршрут site_catalog, найнижчий пріоритет): «/odyah/futbolky/» (кінцевий слеш) — категорія за
 * повним шляхом, «/{slug}» (один сегмент, без слеша) — товар. Решта — 404: без 301 (рішення власника), зокрема
 * «/odyah» без слеша не веде на категорію, а «/tovar/» — на товар. Зі сторінками в корені (app.page_path_prefix: '')
 * «/{slug}/» спершу шукає опубліковану сторінку (Page), потім категорію.
 */
final class SiteCatalogController extends AbstractController
{
    public function __construct(
        private readonly CategoryPageController $categories,
        private readonly ProductPageController $products,
        private readonly PageController $pages,
        private readonly SiteRouteLoader $routes,
    ) {
    }

    public function __invoke(Request $request, string $path): Response
    {
        // сторінки в корені (app.page_path_prefix: ''): «/about/» — спершу опублікована сторінка, далі категорія
        if ($this->routes->pagesAtRoot() && 1 === preg_match('#^('.SiteRouteLoader::SLUG.')/$#', $path, $m)) {
            $page = $this->pages->find($m[1], $request->getLocale());
            if (null !== $page) {
                return $this->pages->page($page);
            }
        }
        if (str_ends_with($path, '/')) {
            return ($this->categories)($request, rtrim($path, '/'));
        }
        if (1 === preg_match('/^'.SiteRouteLoader::SLUG.'$/', $path)) {
            return ($this->products)($request, $path);
        }

        throw $this->createNotFoundException('Сторінку не знайдено');
    }
}

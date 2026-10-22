<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Redirect;

use App\Core\Seo\LiveUrls;
use App\Core\Seo\RedirectPath;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Жива адреса сайту = GET на site_host не дав би 404: маршрут сайту знайдено і, для сторінок сутностей, сутність є й
 * опублікована (товар і категорія активні, сторінка опублікована, стаття з датою публікації в минулому). Маршрути адмінки
 * на хості сайту — 404 (AdminHostGuard), тож не живі. Лише для збереження ручних правил — не на запитах сайту.
 *
 * Матчер бере метод і хост з RequestContext роутера, а він спільний з поточним запитом: у адмінці це POST на admin_host,
 * і site_catalog (GET/HEAD) там не матчиться — жива адреса виглядала б мертвою (рев'ю #37, п. 1). Тому на час перевірки
 * — власний контекст GET https://site_host, потім попередній.
 */
final class SiteLiveUrls implements LiveUrls
{
    public function __construct(
        #[Autowire(service: 'router')] private readonly RouterInterface $router,
        private readonly Connection $db,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire(expression: "parameter('app.page_path_prefix') === ''")] private readonly bool $pagesAtRoot = true,
    ) {
    }

    public function isLive(string $path): bool
    {
        $request = Request::create('https://'.$this->siteHost.RedirectPath::encode($path));
        $previous = $this->router->getContext();
        $this->router->setContext((new RequestContext())->fromRequest($request));
        try {
            $match = $this->router->match($request->getPathInfo());
        } catch (RoutingException) {
            return false;
        } finally {
            $this->router->setContext($previous);
        }
        $route = (string) ($match['_canonical_route'] ?? $match['_route'] ?? '');
        $locale = (string) ($match['_locale'] ?? '');

        return match (true) {
            'admin' === $route || str_starts_with($route, 'admin_') => false,
            'site_catalog' === $route && $this->pagesAtRoot && 1 === preg_match('#^[a-z0-9-]+/$#', (string) $match['path'])
                && $this->exists('SELECT 1 FROM content_page_translation t JOIN content_page p ON p.id = t.page_id WHERE t.locale = ? AND t.slug = ? AND p.published = 1', $locale, rtrim((string) $match['path'], '/')) => true,
            'site_catalog' === $route => str_ends_with((string) $match['path'], '/')
                ? $this->exists('SELECT 1 FROM catalog_category_translation t JOIN catalog_category c ON c.id = t.category_id WHERE t.locale = ? AND t.path = ? AND c.active = 1', $locale, rtrim((string) $match['path'], '/'))
                : $this->exists('SELECT 1 FROM catalog_product_translation t JOIN catalog_product p ON p.id = t.product_id WHERE t.locale = ? AND t.slug = ? AND p.active = 1', $locale, (string) $match['path']),
            'site_page' === $route => $this->exists('SELECT 1 FROM content_page_translation t JOIN content_page p ON p.id = t.page_id WHERE t.locale = ? AND t.slug = ? AND p.published = 1', $locale, (string) $match['slug']),
            'site_post' === $route => $this->exists('SELECT 1 FROM content_post_translation t JOIN content_post p ON p.id = t.post_id WHERE t.locale = ? AND t.slug = ? AND p.published_at <= NOW()', $locale, (string) $match['slug']),
            default => true,
        };
    }

    private function exists(string $sql, string $locale, string $value): bool
    {
        return false !== $this->db->fetchOne($sql, [$locale, $value]);
    }
}

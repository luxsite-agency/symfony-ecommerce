<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Routing;

use App\Core\Catalog\Url\ReservedPathSegments;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Зарезервовані перші сегменти адрес: з усіх маршрутів застосунку (крім catch-all товару й категорії) — cart, checkout,
 * blog, page, order, np, quick-order, price-request, feeds, _profiler…, і за мовним префіксом так само; плюс мовні коди
 * (свої й типові — щоб додавання мови не зламало наявні адреси) і службові шляхи вебсервера та майбутніх модулів.
 */
#[AsAlias(ReservedPathSegments::class)]
final class RouterReservedPathSegments implements ReservedPathSegments
{
    /** Службові шляхи поза маршрутизатором (nginx/Vich/Liip) і зарезервовані наперед (оплата, доставка, акаунт). */
    private const array STATIC = [
        'admin', 'login', 'logout', 'account', 'api', 'media', 'themes', 'assets', 'bundles', 'build', 'uploads', 'feeds',
        'robots.txt', 'favicon.ico', 'sitemap.xml', 'index.php', 'search', 'payment', 'payments', 'shipping', 'liqpay',
        'ukrposhta', 'np', 'cart', 'checkout', 'order', 'orders', 'page', 'blog', 'quick-order', 'price-request', 'solutions',
    ];
    /** Мовні коди наперед: адреса товару «pl» зламалась би, щойно магазин додасть польську. */
    private const array LOCALES = ['uk', 'ru', 'en', 'pl', 'de', 'fr', 'es', 'it', 'cs', 'sk', 'ro', 'hu', 'lt', 'lv', 'et', 'ka', 'kk', 'be', 'bg', 'hr', 'sl', 'sr', 'tr', 'pt', 'nl', 'sv', 'fi', 'da', 'no', 'el', 'he', 'ar', 'zh', 'ja', 'ko'];
    private const array CATCH_ALL = ['site_product', 'site_category', 'site_catalog'];

    /** @var array<string, true>|null */
    private ?array $segments = null;

    /** @param list<string> $locales */
    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire('%app.locales%')] private readonly array $locales,
    ) {
    }

    public function isReserved(string $segment): bool
    {
        return isset($this->load()[strtolower($segment)]);
    }

    public function all(): array
    {
        $all = array_keys($this->load());
        sort($all);

        return array_map(strval(...), $all);
    }

    /** @return array<string, true> */
    private function load(): array
    {
        if (null !== $this->segments) {
            return $this->segments;
        }
        $segments = array_fill_keys([...self::STATIC, ...self::LOCALES, ...$this->locales], true);
        $localeSet = array_flip($this->locales);
        foreach ($this->router->getRouteCollection() as $route) {
            if (\in_array($route->getDefault('_canonical_route'), self::CATCH_ALL, true)) {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $route->getPath()), static fn (string $p): bool => '' !== $p));
            if (isset($parts[0], $localeSet[$parts[0]])) {
                array_shift($parts); // /en/cart/ → cart
            }
            if (isset($parts[0]) && !str_contains($parts[0], '{')) {
                $segments[strtolower($parts[0])] = true;
            }
        }

        return $this->segments = $segments;
    }
}

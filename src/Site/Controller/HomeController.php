<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Query\CatalogHit;
use App\Site\Catalog\GroupPriceOverlay;
use App\Site\Catalog\HomeShowcase;
use App\Site\Catalog\ListingProducts;
use App\Site\Catalog\ProductCards;
use App\Site\Customer\CustomerContext;
use App\Site\SiteNavigation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Головна (маршрут — SiteRouteLoader): банер теми, плитки кореневих категорій (кількість товарів і зображення), вітрина
 * («Популярне» — обрані в адмінці товари, доповнені новинками; без обраних — «Новинки»),
 * блок «куди йдуть кошти», сторінки й останні записи блогу. Товар без перекладу мовою сторінки показується мовою за
 * замовчуванням і веде на її URL (як меню, ADR Rev 8). Оптовику ціни вітрини перераховуються поверх (GroupPriceOverlay).
 */
final class HomeController extends AbstractController
{
    public const int NEWEST = 8;

    public function __construct(
        private readonly HomeShowcase $showcase,
        private readonly ProductCards $cards,
        private readonly ListingProducts $listingProducts,
        private readonly SiteNavigation $navigation,
        private readonly GroupPriceOverlay $groupPrices,
        private readonly CustomerContext $customers,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $locale = $request->getLocale();
        ['hits' => $hits, 'featured' => $featured] = $this->showcase->showcase(self::NEWEST);
        $cards = [];
        $missing = [];
        foreach ($this->cards->load($hits, $locale) as $card) {
            if (null === $card['slug'] && $locale !== $this->defaultLocale) {
                $missing[$card['id']] = true;
                continue;
            }
            $cards[$card['id']] = $card + ['locale' => $locale];
        }
        if ([] !== $missing) {
            $fallback = array_values(array_filter($hits, static fn (CatalogHit $h): bool => isset($missing[$h->productId])));
            foreach ($this->cards->load($fallback, $this->defaultLocale) as $card) {
                $cards[$card['id']] = $card + ['locale' => $this->defaultLocale];
            }
        }
        // порядок вітрини (обрані, потім найновіші; без фото — в кінці)
        $ordered = [];
        foreach ($hits as $hit) {
            if (isset($cards[$hit->productId])) {
                $ordered[] = $cards[$hit->productId];
            }
        }
        $ordered = $this->groupPrices->apply($ordered, $this->customers->group(), $locale);

        $categories = $this->navigation->rootCategories();
        $ids = array_values(array_filter(array_map(static fn (Category $c): ?int => $c->getId(), $categories)));

        return $this->render('site/home.html.twig', [
            'categories' => $categories,
            'tiles' => $this->showcase->categoryTiles($ids),
            'cards' => $ordered,
            'cards_featured' => $featured,
            'analytics_products' => $this->listingProducts->load($hits),
            'analytics_prices' => GroupPriceOverlay::analyticsPrices($ordered),
        ]);
    }
}

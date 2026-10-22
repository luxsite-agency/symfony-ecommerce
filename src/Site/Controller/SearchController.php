<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\CategoryFacetResolver;
use App\Core\Catalog\Search\ProductSearch;
use App\Site\Catalog\CatalogLabels;
use App\Site\Catalog\FacetBlockBuilder;
use App\Site\Catalog\GroupPriceOverlay;
use App\Site\Catalog\ListingFilterFactory;
use App\Site\Catalog\ListingParams;
use App\Site\Catalog\ListingProducts;
use App\Site\Catalog\PageWindow;
use App\Site\Catalog\ProductCards;
use App\Site\Customer\CustomerContext;
use App\Site\SiteUrlGenerator;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Сторінка пошуку /search?q=… (англомовна аптека, 19.10.2026): id товарів з ProductSearch (назва, SKU, бренд) →
 * той самий лістинг, що й категорія (CatalogQuery по read-index: фасети всіх filterable-характеристик, GET-фільтри за
 * схемою ListingParams, сортування, сторінки ?page=N). Завжди noindex (canonical — на себе без сміттєвих параметрів);
 * порожній або закороткий q — форма без результатів, сторінка за межами — 404, нечислова page — 400.
 */
final class SearchController extends AbstractController
{
    public const int PER_PAGE = 24;

    public function __construct(
        private readonly ProductSearch $search,
        private readonly CatalogQuery $catalog,
        private readonly CategoryFacetResolver $facetResolver,
        private readonly CatalogLabels $labels,
        private readonly ListingFilterFactory $filters,
        private readonly FacetBlockBuilder $facetBlocks,
        private readonly ProductCards $cards,
        private readonly ListingProducts $listingProducts,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly GroupPriceOverlay $groupPrices,
        private readonly CustomerContext $customers,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $locale = $request->getLocale();
        $raw = $request->query->get('q');
        $q = ProductSearch::normalize(\is_string($raw) ? $raw : '');

        try {
            $params = ListingParams::fromQuery($request->query->all());
            $definitions = $this->facetResolver->resolve(null);
            $labels = $this->labels->attributes($definitions, $locale);
            $ids = '' === $q ? [] : $this->search->ids($q, $locale);
            [$filter, $effective] = $this->filters->create(null, $params, $definitions, $labels, self::PER_PAGE, $ids);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $result = $this->catalog->search($filter);
        if ($effective->page > max(1, $result->pageCount())) {
            throw $this->createNotFoundException('Такої сторінки нема');
        }

        $query = static fn (ListingParams $p): array => ['_locale' => $locale] + ('' === $q ? [] : ['q' => $q]) + $p->toQuery();
        $url = fn (ListingParams $p): string => $this->generateUrl('site_search', $query($p));
        $absolute = fn (ListingParams $p): string => $this->siteUrls->absolute('site_search', $query($p));

        $sorts = [];
        foreach (CatalogSort::cases() as $sort) {
            $sorts[] = ['value' => $sort->value, 'selected' => $sort === $effective->sort, 'url' => $url($effective->withSort($sort))];
        }
        $pages = array_map(static fn (?int $n): ?array => null === $n ? null : ['number' => $n, 'url' => $url($effective->withPage($n)), 'current' => $n === $effective->page], PageWindow::pages($effective->page, $result->pageCount()));
        $cards = $this->groupPrices->apply($this->cards->load($result->hits, $locale), $this->customers->group(), $locale);

        return $this->render('site/catalog/search.html.twig', [
            'q' => $q,
            'result' => $result,
            'cards' => $cards,
            'analytics_products' => $this->listingProducts->load($result->hits),
            'analytics_prices' => GroupPriceOverlay::analyticsPrices($cards),
            'facets' => '' === $q ? [] : $this->facetBlocks->build($result, $effective, $labels, $locale),
            'params' => $effective,
            'reset_url' => $url($effective->withoutFilters()),
            'sorts' => $sorts,
            'pages' => $pages,
            'prev_url' => $effective->page > 1 ? $absolute($effective->withPage($effective->page - 1)) : null,
            'next_url' => $effective->page < $result->pageCount() ? $absolute($effective->withPage($effective->page + 1)) : null,
            'noindex' => true,
            'alternates' => [$locale => $absolute($effective)],
        ]);
    }
}

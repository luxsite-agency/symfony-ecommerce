<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\CategoryFacetResolver;
use App\Site\Catalog\CatalogLabels;
use App\Site\Catalog\FacetBlockBuilder;
use App\Site\Catalog\GroupPriceOverlay;
use App\Site\Catalog\ListingFilterFactory;
use App\Site\Catalog\ListingParams;
use App\Site\Catalog\ListingProducts;
use App\Site\Catalog\PageWindow;
use App\Site\Catalog\ProductCards;
use App\Site\Customer\CustomerContext;
use App\Site\Routing\SiteUrlResolver;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Сторінка категорії (Ф1, агент B): картки з CatalogQuery (read-index, піддерево включно), фасети з лічильниками,
 * фільтри як GET за схемою ListingParams, пагінація номерами (?page=N) з canonical/prev/next, hreflang по app.locales.
 *
 * SEO: сторінка без фільтрів і з сортуванням за замовчуванням — індексується (canonical на себе, hreflang на ту ж
 * сторінку іншими мовами); з фільтрами/сортуванням — noindex, follow і canonical на нормалізований URL (без сміттєвих
 * параметрів). Сторінка за межами кількості — 404, порожня категорія — 200 з повідомленням. Понад
 * CatalogFilter::MAX_ATTRIBUTES характеристик або нечислова page — 400.
 */
final class CategoryPageController extends AbstractController
{
    public const int PER_PAGE = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogQuery $catalog,
        private readonly CategoryFacetResolver $facetResolver,
        private readonly CatalogLabels $labels,
        private readonly ListingFilterFactory $filters,
        private readonly FacetBlockBuilder $facetBlocks,
        private readonly ProductCards $cards,
        private readonly ListingProducts $listingProducts,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly SiteUrlResolver $urls,
        private readonly GroupPriceOverlay $groupPrices,
        private readonly CustomerContext $customers,
        /** A–Z за першою літерою назви (вмикає пак: app.catalog_letters) */
        #[Autowire('%app.catalog_letters%')] private readonly bool $lettersEnabled = false,
    ) {
    }

    public function __invoke(Request $request, string $path): Response
    {
        $locale = $request->getLocale();
        $id = $this->urls->category($locale, $path);
        $category = null === $id ? null : $this->em->find(Category::class, $id);
        $translation = $category?->getTranslation($locale);
        if (null === $translation || $translation->getPath() !== $path) {
            $id = $this->urls->fresh('c', $locale, $path); // запис кешу застарів (слаг змінили) — ще раз повз кеш
            $category = null === $id ? null : $this->em->find(Category::class, $id);
            $translation = $category?->getTranslation($locale);
            if (null === $translation || $translation->getPath() !== $path) {
                $category = null;
            }
        }
        if (!$category instanceof Category || !self::isVisible($category)) {
            throw $this->createNotFoundException('Категорію не знайдено');
        }

        try {
            $params = ListingParams::fromQuery($request->query->all());
            $definitions = $this->facetResolver->resolve((int) $category->getId());
            $labels = $this->labels->attributes($definitions, $locale);
            [$filter, $effective] = $this->filters->create((int) $category->getId(), $params, $definitions, $labels, self::PER_PAGE, locale: $locale, letters: $this->lettersEnabled);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $result = $this->catalog->search($filter);
        if ($effective->page > $result->pageCount()) {
            throw $this->createNotFoundException('Такої сторінки нема');
        }

        // посилання на сторінці — відносні; canonical/prev/next/hreflang — абсолютні на канонічному домені (site_host)
        $url = fn (ListingParams $p): string => $this->generateUrl('site_category', ['path' => $path, '_locale' => $locale] + $p->toQuery());
        $absolute = fn (ListingParams $p): string => $this->siteUrls->absolute('site_category', ['path' => $path, '_locale' => $locale] + $p->toQuery());
        $indexable = !$effective->hasFilters() && CatalogSort::PriceAsc === $effective->sort;

        // base.html.twig бере canonical з alternates[мова], hreflang — якщо мов більше однієї: фільтрована сторінка
        // отримує лише себе (canonical без hreflang), індексована — ту саму сторінку всіма мовами, де є слаг
        $alternates = [$locale => $absolute($effective)];
        if ($indexable) {
            $alternates = [];
            foreach ($this->siteUrls->locales() as $l) {
                $t = $category->getTranslation($l);
                if ($t instanceof CategoryTranslation && null !== $t->getPath()) {
                    $alternates[$l] = $this->siteUrls->absolute('site_category', ['path' => $t->getPath(), '_locale' => $l] + $effective->toQuery());
                }
            }
        }

        $sorts = [];
        foreach (CatalogSort::cases() as $sort) {
            $sorts[] = ['value' => $sort->value, 'selected' => $sort === $effective->sort, 'url' => $url($effective->withSort($sort))];
        }
        $pages = array_map(static fn (?int $n): ?array => null === $n ? null : ['number' => $n, 'url' => $url($effective->withPage($n)), 'current' => $n === $effective->page], PageWindow::pages($effective->page, $result->pageCount()));
        $letters = $this->lettersEnabled ? self::letters($result->facets->letters, $effective, $url) : [];

        return $this->render('site/catalog/category_index.html.twig', [
            'category' => $category,
            'tr' => $translation,
            'crumbs' => $category->getAncestors(),
            'children' => array_values(array_filter($category->getChildren()->toArray(), static fn (Category $c): bool => $c->isActive() && ($c->getTranslation($locale) instanceof CategoryTranslation) && null !== $c->getTranslation($locale)->getPath())),
            'result' => $result,
            // кеш, фасети й сортування — роздріб; оптовику ціни карток перераховуються поверх, лише у відповіді
            'cards' => $cards = $this->groupPrices->apply($this->cards->load($result->hits, $locale), $this->customers->group(), $locale),
            // ItemList (seo-tech-notes п. 21): товари сторінки на канонічному домені, як canonical і sitemap
            'item_list' => array_values(array_map(fn (array $c): string => $this->siteUrls->absolute('site_product', ['slug' => (string) $c['slug'], '_locale' => $locale]), array_filter($cards, static fn (array $c): bool => null !== $c['slug']))),
            'analytics_products' => $this->listingProducts->load($result->hits),
            'analytics_prices' => GroupPriceOverlay::analyticsPrices($cards),
            'facets' => $this->facetBlocks->build($result, $effective, $labels, $locale),
            'params' => $effective,
            'reset_url' => $url($effective->withoutFilters()),
            'sorts' => $sorts,
            'pages' => $pages,
            'prev_url' => $effective->page > 1 ? $absolute($effective->withPage($effective->page - 1)) : null,
            'next_url' => $effective->page < $result->pageCount() ? $absolute($effective->withPage($effective->page + 1)) : null,
            'noindex' => !$indexable,
            'alternates' => $alternates,
            'letters' => $letters,
        ]);
    }

    /**
     * Рядок A–Z для шаблону: літери з лічильників (у яких є картки з рештою фільтрів) + обрана; якщо всі вони латинські —
     * доповнюється до повного A–Z (недоступні — без посилання), інший алфавіт — лише наявні. Клік по обраній знімає фільтр.
     *
     * @param array<string, int>              $counts
     * @param callable(ListingParams): string $url
     *
     * @return list<array{letter: string, url: string|null, current: bool, available: bool, count: int}>
     */
    private static function letters(array $counts, ListingParams $params, callable $url): array
    {
        $current = null === $params->letter ? null : mb_strtoupper($params->letter);
        $present = array_keys(array_filter($counts, static fn (int $n): bool => $n > 0));
        if (null !== $current && !\in_array($current, $present, true)) {
            $present[] = $current;
        }
        if ([] === $present) {
            return [];
        }
        $alphabet = $present;
        if ([] === array_filter($present, static fn (string $l): bool => 1 !== preg_match('/^[A-Z]$/', $l))) {
            $alphabet = array_unique(array_merge(range('A', 'Z'), $present));
        }
        sort($alphabet, \SORT_STRING);
        $letters = [];
        foreach ($alphabet as $letter) {
            $count = $counts[$letter] ?? 0;
            $isCurrent = $letter === $current;
            $available = $count > 0 || $isCurrent;
            $letters[] = ['letter' => $letter, 'url' => $available ? $url($params->withLetter($isCurrent ? null : mb_strtolower($letter))) : null, 'current' => $isCurrent, 'available' => $available, 'count' => $count];
        }

        return $letters;
    }

    /** Категорія і всі предки активні: вимкнена гілка ховає піддерево. */
    private static function isVisible(Category $category): bool
    {
        foreach ([...$category->getAncestors(), $category] as $node) {
            if (!$node->isActive()) {
                return false;
            }
        }

        return true;
    }
}

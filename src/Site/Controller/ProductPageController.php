<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Viewed\RecentlyViewed;
use App\Core\Shared\Site\SiteName;
use App\Site\Catalog\ProductCardsByIds;
use App\Site\Catalog\ProductOptionsForm;
use App\Site\Catalog\ProductPresenter;
use App\Site\Catalog\ProductReviewsBlock;
use App\Site\Customer\CustomerContext;
use App\Site\Routing\SiteUrlResolver;
use App\Site\Seo\ProductSchema;
use App\Site\Seo\ProductSeo;
use App\Site\Seo\ReviewSchema;
use App\Site\SiteUrlGenerator;
use App\Site\Solution\SolutionFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сторінка товару (Ф1, агент B): картка, перемикач варіантів за осями (GET ?<вісь>=<значення>, без JS), ціна й
 * наявність обраного варіанта, характеристики, зображення, hreflang по app.locales (site_alternates у шаблоні).
 * Неактивний товар, товар без перекладу цією мовою або неіснуючий слаг — 404. canonical — URL товару без параметрів
 * варіанта (варіанти не дублюють сторінку в індексі). Ціна — за групою покупця (CustomerContext), роздріб поруч;
 * SEO-мета й schema Offer лишаються роздрібними. Залогіненому перегляд записується в «переглянуті» (E12 PR-10) і
 * показуються попередні; гість без сесії нічого не пише — сторінка лишається кешованою. Відгуки (ProductReviewsBlock):
 * агрегат і схвалені сторінками (?reviews_page), форма лише покупцю; schema AggregateRating/Review — зі схвалених справжніх.
 */
final class ProductPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductPresenter $presenter,
        private readonly ProductOptionsForm $optionsForm,
        private readonly SiteUrlResolver $urls,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly ProductSeo $seo,
        private readonly ProductSchema $schema,
        private readonly SiteName $siteName,
        private readonly CustomerContext $customers,
        private readonly RecentlyViewed $viewed,
        private readonly ProductCardsByIds $cardsByIds,
        private readonly ProductReviewsBlock $reviewsBlock,
        private readonly ReviewSchema $reviewSchema,
        private readonly SolutionFinder $solutions,
    ) {
    }

    public function __invoke(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();
        $id = $this->urls->product($locale, $slug);
        $product = null === $id ? null : $this->em->find(Product::class, $id);
        $translation = $product?->getTranslation($locale);
        if (null === $translation || $translation->getSlug() !== $slug) {
            $id = $this->urls->fresh('p', $locale, $slug); // запис кешу застарів (слаг змінили) — ще раз повз кеш
            $product = null === $id ? null : $this->em->find(Product::class, $id);
            $translation = $product?->getTranslation($locale);
            if (null === $translation || $translation->getSlug() !== $slug) {
                $product = null;
            }
        }
        if (!$product instanceof Product || !$product->isActive()) {
            throw $this->createNotFoundException('Товар не знайдено');
        }

        $group = $this->customers->group();
        $view = $this->presenter->present($product, $locale, $request->query->all(), $group);
        $url = $this->siteUrls->product($product, $locale) ?? $request->getUri();
        $viewed = [];
        $customer = $this->customers->customer();
        if (null !== $customer) {
            $viewed = $this->cardsByIds->load($this->viewed->productIds($customer, $product->getId()), $locale, $group);
            $this->viewed->record($customer, $product);
        }

        return $this->render('site/catalog/product_show.html.twig', [
            'product' => $product,
            'tr' => $translation,
            'view' => $view,
            'options' => $this->optionsForm->build($product, $view['variant'], $locale, $request->query->all(), $group),
            // мета за шаблонами магазину, коли поля перекладу порожні, з роздрібною ціною; schema Product — бренд і Offer (seo-tech-notes п. 3, 4, 8)
            'seo' => $this->seo->meta($product, $translation, $view['retailPrice'] ?? $view['price'], $locale),
            'schema_brand' => $this->seo->brand($product, $locale),
            'schema_offers' => $this->schema->offers($product, $url, $this->siteName->forLocale($locale), $locale),
            'schema_rating' => $this->reviewSchema->rating($product),
            'reviews' => $this->reviewsBlock->build($product, $locale, $request->query->getInt(ProductReviewsBlock::PAGE_PARAM, 1), $customer),
            'viewed' => $viewed,
            'solutions' => $this->solutions->forProduct($product, $locale),
            // точні залишки й терміни лише групі з прапорцем (E13 PR-7): гість і решта бачать «в наявності»
            'exact_stock' => true === $group?->showsExactStock(),
        ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Twig;

use App\Core\Analytics\AnalyticsTags;
use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Pricing\LineQuote;
use App\Core\Pricing\PriceQuote;
use App\Core\Shared\Money\Money;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Twig: analytics_head()/analytics_body_start()/analytics_body_end() — сирий HTML тегів для layout;
 * analytics_push(event) — подія dataLayer із серверного рендеру; analytics_view_item/list — готові payload;
 * analytics_select_attr(product, list) — атрибут для посилання (select_item по кліку). prices (id товару → Money) —
 * ціни карток лістингу для покупця з групою (GroupPriceOverlay), щоб view_item_list/select_item несли те, що він бачить.
 */
final class AnalyticsExtension extends AbstractExtension
{
    public function __construct(
        private readonly AnalyticsTags $tags,
        private readonly DataLayerCollector $collector,
        private readonly EcommerceEventBuilder $builder,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('analytics_head', fn (): Markup => new Markup($this->tags->head(), 'UTF-8')),
            new TwigFunction('analytics_body_start', fn (): Markup => new Markup($this->tags->bodyStart(), 'UTF-8')),
            new TwigFunction('analytics_body_end', fn (): Markup => new Markup($this->tags->bodyEnd(), 'UTF-8')),
            new TwigFunction('analytics_push', $this->collector->push(...)),
            new TwigFunction('analytics_view_item', $this->viewItem(...)),
            new TwigFunction('analytics_view_item_list', $this->viewItemList(...)),
            new TwigFunction('analytics_select_attr', $this->selectAttr(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * view_item з тією ж ціною, що на картці: з опціями (форма опцій) або без них (ProductPresenter), з акцією. Без
     * quote (ціна за запитом) — ціна варіанта.
     *
     * @return array<string, mixed>
     */
    public function viewItem(Product|ProductVariant $subject, LineQuote|PriceQuote|null $quote = null): array
    {
        $variant = $subject instanceof Product ? $subject->getDefaultVariant() : $subject;
        $quote = $quote instanceof LineQuote ? $quote->quote : $quote;

        return $this->builder->viewItem([$this->builder->item($variant, 1, null, null, $this->locale(), $quote?->price, $quote?->listPrice)]);
    }

    /**
     * @param iterable<Product> $products
     * @param array<int, Money> $prices
     *
     * @return array<string, mixed>
     */
    public function viewItemList(iterable $products, string $listName, array $prices = []): array
    {
        $items = [];
        $index = 0;
        foreach ($products as $product) {
            $items[] = $this->builder->item($product->getDefaultVariant(), 1, $index++, $listName, $this->locale(), $prices[(int) $product->getId()] ?? null);
        }

        return $this->builder->viewItemList($items, $listName);
    }

    public function selectAttr(Product $product, string $listName, int $index = 0, ?Money $price = null): string
    {
        $event = $this->builder->selectItem($this->builder->item($product->getDefaultVariant(), 1, $index, $listName, $this->locale(), $price), $listName);

        return 'data-analytics-select="'.htmlspecialchars(json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), \ENT_QUOTES).'"';
    }

    private function locale(): ?string
    {
        return $this->requests->getCurrentRequest()?->getLocale();
    }
}

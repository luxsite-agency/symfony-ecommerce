<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ціни групи покупця поверх карток лістингу (консиліум 07.10.2026, П3): вибірка, фасети, сортування, кеш catalog.*,
 * фід і sitemap лишаються роздрібними (read-index без груп), а залогіненому оптовику 24 картки сторінки
 * перераховуються одним пакетним запитом варіантів із порогами через той самий фасад Pricing::cardQuote з групою.
 * Ціна картки — мінімум по активних варіантах товару (як min_price індексу, але за групою); роздріб поруч як
 * retailPrice — теж з фасаду (з акціями), лише якщо інший: оптовик без порога на товар бачить роздрібну акцію без
 * підпису. Кеш не чіпається: накладка живе лише у відповіді цього запиту.
 * Точні залишки (E13 PR-7): групі з exactStock картка отримує stock (сума залишку активних варіантів зі складу) і
 * leadTimeDays (найменший термін варіантів під замовлення); гість і група без прапорця цих ключів не мають — у кеш
 * і в розмітку для них число не потрапляє.
 */
final readonly class GroupPriceOverlay
{
    public function __construct(private EntityManagerInterface $em, private Pricing $pricing)
    {
    }

    /**
     * @param list<array{id: int, price: ?Money, priceFrom: bool, availability: string, ...}> $cards
     *
     * @return list<array{id: int, price: ?Money, retailPrice: ?Money, groupPriced?: bool, stock?: ?string, leadTimeDays?: ?int, priceFrom: bool, availability: string, ...}>
     */
    public function apply(array $cards, ?CustomerGroup $group, string $locale): array
    {
        [$prices, $stocks] = null === $group ? [[], []] : $this->prices(array_map(static fn (array $c): int => $c['id'], $cards), $group, $locale);
        foreach ($cards as $i => $card) {
            [$groupPrice, $retail] = $prices[$card['id']] ?? [null, null];
            $cards[$i]['retailPrice'] = null;
            if ($group?->showsExactStock()) {
                $cards[$i]['stock'] = $stocks[$card['id']]['stock'] ?? null;
                $cards[$i]['leadTimeDays'] = $stocks[$card['id']]['lead'] ?? null;
            }
            if (null === $groupPrice || null === $retail || null === $card['price']) {
                continue;
            }
            // роздріб для порівняння — з того ж фасаду (з акціями), а не з індексу: без порога групи ціни рівні, підпису нема
            $cards[$i]['price'] = $groupPrice;
            $cards[$i]['retailPrice'] = $groupPrice->equals($retail) ? null : $retail;
            $cards[$i]['groupPriced'] = true;
        }

        return $cards;
    }

    /**
     * Ціни групи для подій dataLayer лістингу: id товару → ціна картки, лише там, де картка показує ціну.
     *
     * @param list<array{id: int, price: ?Money, groupPriced?: bool, ...}> $cards після apply()
     *
     * @return array<int, Money>
     */
    public static function analyticsPrices(array $cards): array
    {
        $prices = [];
        foreach ($cards as $card) {
            if (($card['groupPriced'] ?? false) && null !== $card['price']) {
                $prices[$card['id']] = $card['price'];
            }
        }

        return $prices;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array{array<int, array{Money, Money}>, array<int, array{stock: ?string, lead: ?int}>} id товару → [мінімальна ціна групи, мінімальна роздрібна] серед активних варіантів; id товару → залишок і термін
     */
    private function prices(array $productIds, CustomerGroup $group, string $locale): array
    {
        if ([] === $productIds) {
            return [[], []];
        }
        // два запити на сторінку (identity map зв'язує їх): варіанти з порогами, товар з категоріями (цілі акцій) і групами
        // опцій (ініціалізує колекцію товару); далі значення груп, підписи й варіанти-компоненти з порогами — усе, що читає
        // OptionPriceCalculator::configure для значень за замовчуванням, інакше лінивий запит на кожну групу кожної картки
        /** @var list<ProductVariant> $variants */
        $variants = $this->em->createQueryBuilder()->select('v', 't', 'tg', 'p', 'c', 'og')->from(ProductVariant::class, 'v')
            ->join('v.product', 'p')->leftJoin('v.priceTiers', 't')->leftJoin('t.customerGroup', 'tg')->leftJoin('p.categories', 'c')
            ->leftJoin('p.optionGroups', 'og')
            ->where('v.product IN (:ids)')->andWhere('v.active = true')->andWhere('p.active = true')
            ->setParameter('ids', $productIds)->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('og', 'ogt', 'ov', 'ovt', 'cv', 'cvt')->from(OptionGroup::class, 'og')
            ->leftJoin('og.translations', 'ogt')->leftJoin('og.values', 'ov')->leftJoin('ov.translations', 'ovt')
            ->leftJoin('ov.componentVariant', 'cv')->leftJoin('cv.priceTiers', 'cvt')
            ->where('og.product IN (:ids)')->setParameter('ids', $productIds)->getQuery()->getResult();

        $prices = $stocks = [];
        foreach ($variants as $variant) {
            $product = $variant->getProduct();
            $id = (int) $product->getId();
            $stocks[$id] ??= ['stock' => null, 'lead' => null];
            if (StockPolicy::InStock === $variant->getStockPolicy()) {
                $stocks[$id]['stock'] = Quantity::display(bcadd($stocks[$id]['stock'] ?? '0', $variant->getStock(), 3));
            } elseif (StockPolicy::MadeToOrder === $variant->getStockPolicy() && null !== $variant->getLeadTimeDays()) {
                $stocks[$id]['lead'] = min($variant->getLeadTimeDays(), $stocks[$id]['lead'] ?? \PHP_INT_MAX);
            }
            if (PriceMode::OnRequest === $product->getPriceMode()) {
                continue;
            }
            $price = $this->pricing->cardQuote($variant, $locale, $group)->price;
            $retail = $this->pricing->cardQuote($variant, $locale, null)->price;
            $prices[$id] = [
                isset($prices[$id]) ? $price->min($prices[$id][0]) : $price,
                isset($prices[$id]) ? $retail->min($prices[$id][1]) : $retail,
            ];
        }

        return [$prices, $stocks];
    }
}

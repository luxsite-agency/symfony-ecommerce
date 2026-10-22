<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\PriceList;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Прайс-лист групи покупців (E13 PR-8): рядки потоком, пам'ять стала. Товари — діапазонами id пакетами (як MerchantFeedGenerator),
 * варіанти пакета з порогами вантажаться в ORM, після пакета EntityManager очищається (не викликати посеред незбереженої
 * роботи з ORM). Ціна — лише через фасад Pricing::line з опціями за замовчуванням (як cardQuote, кошик і PlaceOrder;
 * рев'ю #63 M1), пороги групи → акції, дозволені групі: те саме число, що в кошику оптовика; роздрібна — той самий фасад
 * без групи. Нічого не читає з priceMinor напряму. Пакет вантажить ще категорії з предками, бренд і групи опцій зі
 * значеннями й компонентами (як GroupPriceOverlay), інакше акції з цілями й опції дають N+1 на кожен товар (рев'ю #63 M3).
 *
 * Не потрапляють: вимкнені товари й варіанти, «ціна за запитом», запити на прорахунок (quote), ціна 0, картки без
 * назви хоч однією мовою. Назва й категорія — мовою запиту, інакше мовою магазину за замовчуванням, інакше SKU.
 * Наявність: точне число лише коли ExactStockPolicy дозволяє групі (PR-7), інакше слово.
 */
final class PriceListGenerator
{
    public const string IN_STOCK = 'in_stock';
    public const string OUT_OF_STOCK = 'out_of_stock';
    public const string TO_ORDER = 'to_order';

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly Pricing $pricing,
        private readonly ExactStockPolicy $exactStock,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale = 'uk',
    ) {
    }

    /** @return Generator<int, PriceListRow> */
    public function rows(CustomerGroup $group, string $locale, int $batch = 500): Generator
    {
        $groupId = $group->getId() ?? throw new InvalidArgumentException('Група не збережена');
        $exact = $this->exactStock->appliesTo($group);
        $categories = $this->categoryPaths($locale);
        $maxId = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM catalog_product');
        for ($from = 1; $from <= $maxId; $from += $batch) {
            $ids = array_map(intval(...), $this->db->fetchFirstColumn(
                'SELECT id FROM catalog_product WHERE id BETWEEN ? AND ? AND active = 1 AND price_mode <> ? AND kind <> ? ORDER BY id',
                [$from, $from + $batch - 1, PriceMode::OnRequest->value, ProductKind::Quote->value],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
            ));
            if ([] === $ids) {
                continue;
            }
            // група після $em->clear() стає відокремленою; PriceResolver і акції порівнюють групи за id, тож беремо свіжий екземпляр
            $group = $this->em->find(CustomerGroup::class, $groupId) ?? throw new RuntimeException('Групу видалено під час генерації');
            // дерево категорій цілком (предки для Promotion::appliesTo) — поки в identity map, лінивих запитів на parent нема
            $this->em->createQueryBuilder()->select('cat', 'par')->from(Category::class, 'cat')->leftJoin('cat.parent', 'par')->orderBy('cat.id', 'ASC')->getQuery()->getResult();
            /** @var list<ProductVariant> $variants */
            $variants = $this->em->createQueryBuilder()->select('v', 'p', 'pt', 't', 'tg', 'c', 'cats', 'b', 'og')->from(ProductVariant::class, 'v')
                ->join('v.product', 'p')->leftJoin('p.translations', 'pt')->leftJoin('v.priceTiers', 't')->leftJoin('t.customerGroup', 'tg')
                ->leftJoin('p.mainCategory', 'c')->leftJoin('p.categories', 'cats')->leftJoin('p.brand', 'b')->leftJoin('p.optionGroups', 'og')
                ->where('p.id IN (:ids)')->andWhere('v.active = true')->andWhere('v.priceMinor > 0')
                ->setParameter('ids', $ids)
                ->orderBy('p.id', 'ASC')->addOrderBy('v.position', 'ASC')->addOrderBy('v.id', 'ASC')
                ->getQuery()->getResult();
            $this->em->createQueryBuilder()->select('og', 'ogt', 'ov', 'ovt', 'cv', 'cvt')->from(OptionGroup::class, 'og')
                ->leftJoin('og.translations', 'ogt')->leftJoin('og.values', 'ov')->leftJoin('ov.translations', 'ovt')
                ->leftJoin('ov.componentVariant', 'cv')->leftJoin('cv.priceTiers', 'cvt')
                ->where('og.product IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
            foreach ($variants as $variant) {
                $name = $this->name($variant->getProduct(), $locale) ?? $variant->getSku();
                $mainCategory = $variant->getProduct()->getMainCategory();
                $category = null !== $mainCategory ? ($categories[(int) $mainCategory->getId()] ?? '') : '';
                $availability = self::availability($variant);
                $stock = $exact && StockPolicy::InStock === $variant->getStockPolicy() ? Quantity::normalize($variant->getStock()) : null;
                foreach ($this->quantities($variant, $group) as $quantity) {
                    yield new PriceListRow(
                        $variant->getSku(),
                        $name,
                        $category,
                        $variant->getUnit()->value,
                        $quantity,
                        $this->unitPrice($variant, $quantity, $group, $locale),
                        $this->unitPrice($variant, $quantity, null, $locale),
                        $availability,
                        $stock,
                        Quantity::normalize($variant->getMinQuantity()),
                        Quantity::normalize($variant->getQuantityStep()),
                        null !== $variant->getPackQuantity() ? Quantity::normalize($variant->getPackQuantity()) : null,
                    );
                }
            }
            $this->em->clear();
        }
    }

    /** Як Pricing::cardQuote: опції за замовчуванням; обов'язкова група без default — ціна без опцій. */
    private function unitPrice(ProductVariant $variant, string $quantity, ?CustomerGroup $group, string $locale): Money
    {
        try {
            return $this->pricing->line($variant, $quantity, null, $group, $locale)->unitPrice();
        } catch (InvalidOptionsException) {
            return $this->pricing->quote($variant, $quantity, $group)->price;
        }
    }

    /**
     * Кількості «від»: мінімальна партія і кожен чинний поріг вище за неї. Чинні — як у PriceResolver: пороги групи,
     * якщо у варіанта є хоч один, інакше загальні.
     *
     * @return list<string>
     */
    private function quantities(ProductVariant $variant, CustomerGroup $group): array
    {
        $general = $own = [];
        foreach ($variant->getPriceTiers() as $tier) {
            $tierGroup = $tier->getCustomerGroup();
            if (null === $tierGroup) {
                $general[] = $tier->getMinQuantity();
            } elseif ($tierGroup->is($group)) {
                $own[] = $tier->getMinQuantity();
            }
        }
        $min = Quantity::normalize($variant->getMinQuantity());
        $quantities = [$min => true];
        foreach ([] !== $own ? $own : $general as $quantity) {
            if (Quantity::compare($quantity, $min) > 0) {
                $quantities[Quantity::normalize($quantity)] = true;
            }
        }
        $list = array_keys($quantities);
        usort($list, Quantity::compare(...));

        return $list;
    }

    private function name(Product $product, string $locale): ?string
    {
        foreach (array_unique([$locale, $this->defaultLocale]) as $candidate) {
            $name = trim((string) $product->getTranslation($candidate)?->getName());
            if ('' !== $name) {
                return $name;
            }
        }

        return null;
    }

    /** Як ProductPresenter::availability: залишок має покривати мінімальну кількість (менше кошик не дасть). */
    private static function availability(ProductVariant $variant): string
    {
        return match ($variant->getStockPolicy()) {
            StockPolicy::NotTracked => self::IN_STOCK,
            StockPolicy::MadeToOrder => self::TO_ORDER,
            StockPolicy::InStock => Quantity::compare($variant->getStock(), '0') > 0 && Quantity::compare($variant->getStock(), $variant->getMinQuantity()) >= 0 ? self::IN_STOCK : self::OUT_OF_STOCK,
        };
    }

    /**
     * Шлях основної категорії («Двері / Міжкімнатні») мовою запиту з фолбеком на мову за замовчуванням, одним запитом на весь прайс.
     *
     * @return array<int, string>
     */
    private function categoryPaths(string $locale): array
    {
        $nodes = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT c.id, c.parent_id, COALESCE(NULLIF(t.name, \'\'), d.name) AS name
             FROM catalog_category c
             LEFT JOIN catalog_category_translation t ON t.category_id = c.id AND t.locale = ?
             LEFT JOIN catalog_category_translation d ON d.category_id = c.id AND d.locale = ?',
            [$locale, $this->defaultLocale],
        ) as $r) {
            $nodes[(int) $r['id']] = ['parent' => null !== $r['parent_id'] ? (int) $r['parent_id'] : null, 'name' => (string) ($r['name'] ?? '')];
        }
        $paths = [];
        foreach ($nodes as $id => $node) {
            $names = [];
            $seen = [];
            for ($cur = $id; null !== $cur && isset($nodes[$cur]) && !isset($seen[$cur]); $cur = $nodes[$cur]['parent']) {
                $seen[$cur] = true;
                if ('' !== $nodes[$cur]['name']) {
                    array_unshift($names, $nodes[$cur]['name']);
                }
            }
            $paths[$id] = implode(' / ', $names);
        }

        return $paths;
    }
}

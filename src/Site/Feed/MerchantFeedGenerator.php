<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\DoctrinePromotionProvider;
use App\Core\Pricing\Promotion\PromotionProviderInterface;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Runtime\BuildInfo;
use App\Core\Shared\Site\SiteName;
use App\Site\Catalog\ListingFilterFactory;
use App\Site\SiteUrlGenerator;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use XMLWriter;

/**
 * Товарні фіди (ADR Ф3): RSS 2.0 з простором імен g:, окремий файл на кожну мову app.locales і кожен канал (FeedChannel:
 * Google Merchant, Meta). Один прохід по каталогу пише всі канали: item будується один раз, профіль каналу змінює лише
 * відмінності специфікацій (довжини, обов'язковий brand, значення availability, непідтримувані поля).
 *
 * Item = варіант (g:id = SKU = item_id у dataLayer, EcommerceEventBuilder — динамічний ремаркетинг Google Ads і Meta
 * зіставляють подію з товаром каталогу саме за ним), варіанти однієї картки — спільний g:item_group_id.
 *
 * Ціна — Pricing::cardQuote, як на картці, у schema Offer і dataLayer (пороги на мінімальній кількості, опції за
 * замовчуванням, акції): Merchant і Meta відхиляють товар, якщо ціна у фіді інша, ніж на сторінці. Перекреслена ціна —
 * PriceQuote::compareAt (ціна до акції або ручна стара): тоді g:price = перекреслена, g:sale_price = до сплати, а для
 * акції з датою завершення — g:sale_price_effective_date. Мінімальна кількість > 1: ціна — за мінімальну партію (Merchant
 * порівнює з тим, що можна покласти в кошик), штучні — g:multipack, метраж/вага — g:unit_pricing_measure.
 *
 * Наявність: in_stock (облікований залишок покриває мінімальну кількість — як ProductPresenter::availability),
 * out_of_stock, backorder (під замовлення, з availability_date = сьогодні + leadTimeDays). Бренд, gtin (штрихкод з
 * валідною контрольною сумою), mpn (SKU, якщо є бренд), фото (спершу фото варіанта), product_type — шлях основної
 * категорії. Посилання варіанта — сторінка товару з параметрами його осей (?<код осі>=<код значення>, як у перемикачі), тож
 * ціна й наявність на сторінці ті самі, що у фіді. Осі «колір»/«розмір» (app.merchant_feed_axis_codes) — g:color / g:size.
 * g:custom_label_0..4 — з app.feed_custom_labels (категорія, бренд, акція).
 *
 * Не потрапляють: вимкнені товари/варіанти, «ціна за запитом» (Merchant вимагає ціну), послуги й quote, картки без
 * перекладу/слага цією мовою (нема сторінки), ціна 0.
 *
 * Пам'ять стала: товари діапазонами id (keyset) пакетами, вибірка DBAL; для ціни варіанти пакета вантажаться в ORM і після
 * пакета EntityManager очищається (не викликати посеред незбереженої роботи з ORM). XMLWriter пише потоком у тимчасові
 * файли, наприкінці — атомарний rename (читачі бачать або старий фід, або новий цілком).
 */
final class MerchantFeedGenerator
{
    public const string NS = 'http://base.google.com/ns/1.0';
    /** Елементи RSS 2.0 item без префікса g:. */
    private const array RSS_FIELDS = ['title', 'description', 'link'];
    private const int ADDITIONAL_IMAGES_MAX = 20;
    private const int CUSTOM_LABEL_MAX = 100;
    private const array LABEL_SOURCES = ['top_category', 'category', 'brand', 'sale'];
    /** Merchant вимагає дату для backorder; без leadTimeDays на варіанті — стільки днів. */
    private const int DEFAULT_LEAD_DAYS = 14;
    /** Одиниця продажу (SalesUnit) → одиниця Merchant для g:unit_pricing_measure; штуки й комплекти — g:multipack. */
    private const array MEASURE = ['m' => 'm', 'm2' => 'sqm', 'kg' => 'kg'];
    private const string DATE = 'Y-m-d\TH:iO';

    /**
     * @param array<string, list<string>> $axisCodes    g:color / g:size → коди атрибутів-осей (точно або з суфіксом «_…» / «-…»)
     * @param array<string, string>       $customLabels custom_label_N → джерело (LABEL_SOURCES)
     * @param array<string, string>       $saleLabels   sale / regular → значення мітки «акція»
     */
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly Pricing $pricing,
        private readonly PromotionProviderInterface $promotions,
        private readonly Settings $settings,
        private readonly SiteUrlGenerator $urls,
        private readonly MerchantFeedPaths $paths,
        private readonly SiteName $siteName,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire('%app.merchant_feed_axis_codes%')] private readonly array $axisCodes = [],
        #[Autowire('%app.feed_custom_labels%')] private readonly array $customLabels = [],
        #[Autowire('%app.feed_sale_labels%')] private readonly array $saleLabels = [],
    ) {
    }

    /**
     * @param callable(int, int): void|null $progress (оброблено товарів до id, max id)
     * @param list<FeedChannel>|null        $channels null — усі канали
     *
     * @return array{locale: string, items: int, products: int, path: string, paths: array<string, string>, seconds: float} path — фід Google (або першого каналу), paths — канал → файл
     */
    public function generate(string $locale, int $batch = 500, ?callable $progress = null, ?array $channels = null): array
    {
        $started = microtime(true);
        $selected = [];
        foreach ([] === ($channels ?? []) ? FeedChannel::cases() : $channels as $channel) {
            $selected[$channel->value] = $channel;
        }
        $this->assertLabels();
        $siteName = $this->siteName->forLocale($locale);
        $fallbackBrand = trim((string) $this->settings->get(SettingKeys::FEEDS_DEFAULT_BRAND)) ?: $siteName;

        /** @var array<string, XMLWriter> $writers */
        $writers = [];
        /** @var array<string, array{tmp: string, target: string}> $files */
        $files = [];
        $items = $products = 0;
        try {
            foreach ($selected as $key => $channel) {
                $target = $this->paths->file($locale, $channel);
                $dir = \dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Не вдалося створити '.$dir);
                }
                $files[$key] = ['tmp' => $target.'.'.bin2hex(random_bytes(4)).'.tmp', 'target' => $target];
                $writers[$key] = new XMLWriter();
                if (!$writers[$key]->openUri($files[$key]['tmp'])) {
                    throw new RuntimeException('Не вдалося відкрити '.$files[$key]['tmp']);
                }
                $this->startFeed($writers[$key], $siteName, $locale);
            }

            $categories = $this->categoryPaths($locale);
            $today = new DateTimeImmutable('today');
            $this->resetPromotions(); // свіжі акції, а не кеш хвилини з попереднього повідомлення воркера
            $windows = $this->promotionWindows();
            $maxId = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM catalog_product');
            for ($from = 1; $from <= $maxId; $from += $batch) {
                $to = $from + $batch - 1;
                $rows = $this->batch($locale, $from, $to);
                $quotes = $this->quotes($rows, $locale);
                foreach ($rows as $product) {
                    $built = $this->items($locale, $product, $quotes, $categories, $windows, $today);
                    foreach ($writers as $key => $writer) {
                        foreach ($built as $item) {
                            $this->writeItem($writer, $selected[$key], $item, $fallbackBrand);
                        }
                    }
                    $items += \count($built);
                    $products += [] !== $built ? 1 : 0;
                }
                // сутності пакета більше не потрібні: пам'ять стала на будь-якому каталозі; кеш акцій тримає сутності
                // з очищеного identity map (appliesTo порівнює товари за ідентичністю) — перечитати
                $this->em->clear();
                $this->resetPromotions();
                foreach ($writers as $writer) {
                    $writer->flush();
                }
                if (null !== $progress) {
                    $progress(min($to, $maxId), $maxId);
                }
            }

            foreach ($writers as $writer) {
                $writer->endElement();
                $writer->endElement();
                $writer->endDocument();
                $writer->flush();
            }
        } catch (Throwable $e) {
            unset($writer);
            $writers = []; // закрити файли до видалення
            foreach ($files as $file) {
                @unlink($file['tmp']);
            }
            throw $e;
        }
        unset($writer);
        $writers = []; // деструктор XMLWriter закриває файл

        // rename лише після того, як дописано всі канали: збій генерації не підміняє жодного фіду
        $paths = [];
        foreach ($files as $key => $file) {
            chmod($file['tmp'], 0o664);
            if (!rename($file['tmp'], $file['target'])) {
                foreach ($files as $other) {
                    @unlink($other['tmp']);
                }
                throw new RuntimeException('Не вдалося замінити '.$file['target']);
            }
            $paths[$key] = $file['target'];
        }

        return [
            'locale' => $locale, 'items' => $items, 'products' => $products,
            'path' => $paths[FeedChannel::Google->value] ?? (string) reset($paths), 'paths' => $paths,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    private function startFeed(XMLWriter $xml, string $siteName, string $locale): void
    {
        // рядок на елемент без відступів: файл читається grep/diff, розмір майже той самий
        $xml->setIndent(true);
        $xml->setIndentString('');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->writeComment(' '.BuildInfo::LINE.' ');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::NS);
        $xml->startElement('channel');
        self::element($xml, 'title', $siteName);
        self::element($xml, 'link', 'https://'.$this->siteHost.'/');
        self::element($xml, 'description', $siteName.' — '.$locale);
        self::element($xml, 'generator', BuildInfo::NAME);
    }

    /**
     * Товари діапазону id з варіантами, осями й фото — 4 запити на пакет.
     *
     * @return list<array<string, mixed>>
     */
    private function batch(string $locale, int $from, int $to): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.main_category_id, b.name AS brand, t.name, t.slug, t.short_description, t.description
             FROM catalog_product p
             JOIN catalog_product_translation t ON t.product_id = p.id AND t.locale = ? AND t.slug IS NOT NULL AND t.slug <> \'\'
             LEFT JOIN catalog_brand b ON b.id = p.brand_id
             WHERE p.id BETWEEN ? AND ? AND p.active = 1 AND p.price_mode <> ? AND p.kind NOT IN (?)
             ORDER BY p.id',
            [$locale, $from, $to, PriceMode::OnRequest->value, [ProductKind::Service->value, ProductKind::Quote->value]],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::STRING],
        );
        if ([] === $rows) {
            return [];
        }
        $products = [];
        foreach ($rows as $row) {
            $products[(int) $row['id']] = $row + ['variants' => [], 'images' => [], 'variantImages' => []];
        }
        $ids = array_keys($products);

        foreach ($this->db->fetchAllAssociative(
            'SELECT id, product_id, sku, barcode, price_minor, currency, stock, stock_policy, lead_time_days, unit, min_quantity
             FROM catalog_product_variant WHERE product_id IN (?) AND active = 1 AND price_minor > 0 ORDER BY product_id, position, id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $v) {
            $products[(int) $v['product_id']]['variants'][(int) $v['id']] = $v + ['axes' => []];
        }

        foreach ($this->db->fetchAllAssociative(
            'SELECT v.product_id, ax.variant_id, a.code AS axis, av.code AS value, COALESCE(avt.value, av.code) AS label
             FROM catalog_product_variant v
             JOIN catalog_product_variant_axis_value ax ON ax.variant_id = v.id
             JOIN catalog_attribute_value av ON av.id = ax.attribute_value_id
             JOIN catalog_attribute a ON a.id = ax.attribute_id
             LEFT JOIN catalog_attribute_value_translation avt ON avt.attribute_value_id = av.id AND avt.locale = ?
             WHERE v.product_id IN (?) ORDER BY v.product_id, ax.variant_id, a.position, a.id',
            [$locale, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        ) as $ax) {
            $productId = (int) $ax['product_id'];
            $variantId = (int) $ax['variant_id'];
            if (isset($products[$productId]['variants'][$variantId])) {
                $products[$productId]['variants'][$variantId]['axes'][] = ['axis' => (string) $ax['axis'], 'value' => (string) $ax['value'], 'label' => (string) $ax['label']];
            }
        }

        foreach ($this->db->fetchAllAssociative(
            'SELECT pm.product_id, pm.variant_id, m.path FROM catalog_product_media pm JOIN catalog_media m ON m.id = pm.media_id
             WHERE pm.product_id IN (?) ORDER BY pm.product_id, pm.position, pm.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $m) {
            $url = 'https://'.$this->siteHost.'/media/'.ltrim((string) $m['path'], '/');
            if (null === $m['variant_id']) {
                $products[(int) $m['product_id']]['images'][] = $url;
            } else {
                $products[(int) $m['product_id']]['variantImages'][(int) $m['variant_id']][] = $url;
            }
        }

        return array_values($products);
    }

    /**
     * Ціни варіантів пакета через фасад Pricing (та сама ціна, що на картці): варіанти з порогами, товарами, брендами й
     * категоріями (цілі акцій) — одним запитом, групи опцій зі значеннями — другим, щоб cardQuote не ходив у БД на кожен товар.
     *
     * @param list<array<string, mixed>> $products
     *
     * @return array<int, array{price: Money, compareAt: ?Money, promotions: list<int>}> id варіанта →
     */
    private function quotes(array $products, string $locale): array
    {
        $variantIds = $productIds = [];
        foreach ($products as $product) {
            /** @var array<int, mixed> $variants */
            $variants = $product['variants'];
            if ([] !== $variants) {
                $productIds[] = (int) $product['id'];
                array_push($variantIds, ...array_keys($variants));
            }
        }
        if ([] === $variantIds) {
            return [];
        }
        /** @var list<ProductVariant> $entities */
        $entities = $this->em->createQueryBuilder()->select('v', 'p', 't', 'b', 'c')->from(ProductVariant::class, 'v')
            ->join('v.product', 'p')->leftJoin('v.priceTiers', 't')->leftJoin('p.brand', 'b')->leftJoin('p.categories', 'c')
            ->where('v.id IN (:ids)')->setParameter('ids', $variantIds)
            ->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('p', 'g', 'gv')->from(Product::class, 'p')
            ->leftJoin('p.optionGroups', 'g')->leftJoin('g.values', 'gv')
            ->where('p.id IN (:ids)')->setParameter('ids', $productIds)
            ->getQuery()->getResult();

        $quotes = [];
        foreach ($entities as $variant) {
            $quote = $this->pricing->cardQuote($variant, $locale, null); // фід — завжди роздріб, групи покупців сюди не потрапляють
            $promotions = [];
            foreach ($quote->promotions as $promotion) {
                if (null !== $promotion['id']) {
                    $promotions[] = $promotion['id'];
                }
            }
            $quotes[(int) $variant->getId()] = ['price' => $quote->price, 'compareAt' => $quote->compareAt($variant->getOldPrice()), 'promotions' => $promotions];
        }

        return $quotes;
    }

    /**
     * Item картки в порядку полів: [поле, значення] (поле без префікса; title/description/link — елементи RSS, решта — g:).
     *
     * @param array<string, mixed>                                                      $product
     * @param array<int, array{price: Money, compareAt: ?Money, promotions: list<int>}> $quotes
     * @param array<int, list<string>>                                                  $categories
     * @param array<int, array{?DateTimeImmutable, ?DateTimeImmutable}>                 $windows
     *
     * @return list<list<array{string, string}>>
     */
    private function items(string $locale, array $product, array $quotes, array $categories, array $windows, DateTimeImmutable $today): array
    {
        /** @var array<int, array<string, mixed>> $all */
        $all = $product['variants'];
        $variants = array_filter($all, static fn (array $v, int $id): bool => isset($quotes[$id]) && $quotes[$id]['price']->isPositive(), \ARRAY_FILTER_USE_BOTH);
        if ([] === $variants) {
            return [];
        }
        $route = ['slug' => (string) $product['slug'], '_locale' => $locale];
        $description = self::plain((string) ($product['description'] ?? '') ?: (string) ($product['short_description'] ?? ''));
        $brand = null !== $product['brand'] && '' !== trim((string) $product['brand']) ? trim((string) $product['brand']) : null;
        $category = $categories[(int) $product['main_category_id']] ?? [];
        $grouped = \count($variants) > 1;

        $items = [];
        foreach ($variants as $variantId => $v) {
            $quote = $quotes[$variantId];
            $item = [['id', (string) $v['sku']]];
            if ($grouped) {
                $item[] = ['item_group_id', 'P'.$product['id']];
            }
            /** @var list<array{axis: string, value: string, label: string}> $axes */
            $axes = $v['axes'];
            $title = (string) $product['name'];
            if ([] !== $axes) {
                $title .= ', '.implode(', ', array_column($axes, 'label'));
            }
            $item[] = ['title', $title];
            $item[] = ['description', '' !== $description ? $description : (string) $product['name']];
            $item[] = ['link', $this->urls->absolute('site_product', self::variantQuery($axes) + $route)];

            /** @var list<string> $images */
            $images = [...($product['variantImages'][$variantId] ?? []), ...$product['images']];
            $images = array_values(array_unique($images));
            if ([] !== $images) {
                $item[] = ['image_link', $images[0]];
                foreach (\array_slice($images, 1, self::ADDITIONAL_IMAGES_MAX) as $image) {
                    $item[] = ['additional_image_link', $image];
                }
            }

            [$availability, $date] = self::availability($v, $today);
            $item[] = ['availability', $availability];
            if (null !== $date) {
                $item[] = ['availability_date', $date];
            }
            $min = self::minQuantity($v);
            $price = self::times($quote['price']->amount, $min);
            $old = null !== $quote['compareAt'] ? self::times($quote['compareAt']->amount, $min) : null;
            $currency = $quote['price']->currency;
            $sale = null !== $old && $old > $price;
            if ($sale) {
                $item[] = ['price', self::money($old, $currency)];
                $item[] = ['sale_price', self::money($price, $currency)];
                $effective = self::saleWindow($quote['promotions'], $windows, $today);
                if (null !== $effective) {
                    $item[] = ['sale_price_effective_date', $effective];
                }
            } else {
                $item[] = ['price', self::money($price, $currency)];
            }
            if (null !== $min) {
                $measure = self::MEASURE[(string) $v['unit']] ?? null;
                if (null !== $measure) {
                    $item[] = ['unit_pricing_measure', self::decimal($min).' '.$measure];
                    $item[] = ['unit_pricing_base_measure', '1 '.$measure];
                } elseif (1 === preg_match('/^\d+$/', self::decimal($min))) {
                    $item[] = ['multipack', self::decimal($min)];
                }
            }
            $item[] = ['condition', 'new'];
            foreach ($this->axisCodes as $field => $codes) {
                foreach ($axes as $axis) {
                    if (self::axisMatches($axis['axis'], $codes)) {
                        $item[] = [$field, mb_substr($axis['label'], 0, 100)];
                        break;
                    }
                }
            }

            $gtin = self::gtin(null !== $v['barcode'] ? (string) $v['barcode'] : null);
            if (null !== $brand) {
                $item[] = ['brand', $brand];
            }
            if (null !== $gtin) {
                $item[] = ['gtin', $gtin];
            }
            if (null !== $brand) {
                $item[] = ['mpn', (string) $v['sku']];
            }
            if (null === $gtin && null === $brand) {
                $item[] = ['identifier_exists', 'no'];
            }
            if ([] !== $category) {
                $item[] = ['product_type', implode(' > ', $category)];
            }
            foreach ($this->customLabels as $field => $source) {
                $value = match ($source) {
                    'top_category' => $category[0] ?? null,
                    'category' => [] !== $category ? $category[\count($category) - 1] : null,
                    'brand' => $brand,
                    'sale' => $this->saleLabels[$sale ? 'sale' : 'regular'] ?? null,
                    default => null,
                };
                if (null !== $value && '' !== trim($value)) {
                    $item[] = [$field, mb_substr(trim($value), 0, self::CUSTOM_LABEL_MAX)];
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Item у фід каналу: довжини, значення й набір полів — за профілем FeedChannel.
     *
     * @param list<array{string, string}> $item
     */
    private function writeItem(XMLWriter $xml, FeedChannel $channel, array $item, string $fallbackBrand): void
    {
        $xml->startElement('item');
        $brand = false;
        $additional = 0;
        foreach ($item as [$field, $value]) {
            if (!$channel->supports($field)) {
                continue;
            }
            switch ($field) {
                case 'title':
                    $value = mb_substr($value, 0, $channel->titleMax());
                    break;
                case 'description':
                    $value = mb_substr($value, 0, $channel->descriptionMax());
                    break;
                case 'brand':
                    $value = mb_substr($value, 0, $channel->brandMax());
                    $brand = true;
                    break;
                case 'availability':
                    $value = $channel->availability($value);
                    break;
                case 'sale_price_effective_date':
                    $value = $channel->dateRange($value);
                    break;
                case 'additional_image_link':
                    if (++$additional > $channel->additionalImagesMax()) {
                        continue 2;
                    }
                    break;
            }
            if (\in_array($field, self::RSS_FIELDS, true)) {
                self::element($xml, $field, $value);
            } else {
                self::g($xml, $field, $value);
            }
        }
        if (!$brand && $channel->requiresBrand() && '' !== $fallbackBrand) {
            self::g($xml, 'brand', mb_substr($fallbackBrand, 0, $channel->brandMax()));
        }
        $xml->endElement();
    }

    /**
     * Період sale_price для акції з датою завершення: від найпізнішого старту до найранішого кінця застосованих акцій
     * (без старту — з початку сьогодні). Ручна стара ціна чи безстрокова акція — null: знижка діє, доки фід її показує.
     *
     * @param list<int>                                                 $promotionIds
     * @param array<int, array{?DateTimeImmutable, ?DateTimeImmutable}> $windows
     */
    private static function saleWindow(array $promotionIds, array $windows, DateTimeImmutable $today): ?string
    {
        $start = $end = null;
        foreach ($promotionIds as $id) {
            [$from, $to] = $windows[$id] ?? [null, null];
            if (null !== $from && (null === $start || $from > $start)) {
                $start = $from;
            }
            if (null !== $to && (null === $end || $to < $end)) {
                $end = $to;
            }
        }

        return null === $end ? null : ($start ?? $today)->format(self::DATE).'/'.$end->format(self::DATE);
    }

    /**
     * Строки чинних акцій (id → [початок, кінець]) — скаляри, а не сутності: EntityManager очищається після кожного пакета.
     *
     * @return array<int, array{?DateTimeImmutable, ?DateTimeImmutable}>
     */
    private function promotionWindows(): array
    {
        $windows = [];
        foreach ($this->promotions->running(new DateTimeImmutable()) as $promotion) {
            $id = $promotion->getId();
            if (null !== $id) {
                $windows[$id] = [$promotion->getStartsAt(), $promotion->getEndsAt()];
            }
        }

        return $windows;
    }

    private function resetPromotions(): void
    {
        if ($this->promotions instanceof DoctrinePromotionProvider) {
            $this->promotions->reset();
        }
    }

    private function assertLabels(): void
    {
        foreach ($this->customLabels as $field => $source) {
            if (1 !== preg_match('/^custom_label_[0-4]$/', (string) $field) || !\in_array($source, self::LABEL_SOURCES, true)) {
                throw new InvalidArgumentException(\sprintf('app.feed_custom_labels: «%s: %s» — очікується custom_label_0..4 → %s', $field, $source, implode(' | ', self::LABEL_SOURCES)));
            }
        }
    }

    /**
     * Шлях «Корінь > … > Категорія» мовою фіду для кожної категорії (назви від кореня).
     *
     * @return array<int, list<string>>
     */
    private function categoryPaths(string $locale): array
    {
        $nodes = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT c.id, c.parent_id, t.name FROM catalog_category c LEFT JOIN catalog_category_translation t ON t.category_id = c.id AND t.locale = ?',
            [$locale],
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
            if ([] !== $names) {
                $paths[$id] = $names;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $variant
     *
     * @return array{string, string|null}
     */
    private static function availability(array $variant, DateTimeImmutable $today): array
    {
        return match (StockPolicy::from((string) $variant['stock_policy'])) {
            StockPolicy::NotTracked => ['in_stock', null],
            // як ProductPresenter::availability: залишок має покривати мінімальну кількість (менше кошик не дасть)
            StockPolicy::InStock => [self::coversMinimum($variant) ? 'in_stock' : 'out_of_stock', null],
            StockPolicy::MadeToOrder => ['backorder', $today->modify(\sprintf('+%d days', max(1, (int) ($variant['lead_time_days'] ?? self::DEFAULT_LEAD_DAYS))))->format(self::DATE)],
        };
    }

    /** @param array<string, mixed> $variant */
    private static function coversMinimum(array $variant): bool
    {
        $stock = (string) $variant['stock'];

        return 1 === bccomp($stock, '0', 3) && bccomp($stock, (string) ($variant['min_quantity'] ?? '1'), 3) >= 0;
    }

    /**
     * Параметри осей для посилання на варіант — ті самі, що будує перемикач на сторінці (ProductPresenter): код осі →
     * код значення, ключі за алфавітом. Коди, що збіглися б з параметрами маршруту, пропускаються.
     *
     * @param list<array{axis: string, value: string, label: string}> $axes
     *
     * @return array<string, string>
     */
    private static function variantQuery(array $axes): array
    {
        $query = [];
        foreach ($axes as $axis) {
            if (!\in_array($axis['axis'], ['slug', '_locale', '_format', '_fragment'], true)) {
                $query[$axis['axis']] = $axis['value'];
            }
        }
        ksort($query);

        return $query;
    }

    /** @param list<string> $codes */
    private static function axisMatches(string $code, array $codes): bool
    {
        foreach ($codes as $candidate) {
            if ($code === $candidate || str_starts_with($code, $candidate.'_') || str_starts_with($code, $candidate.'-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Мінімальна кількість замовлення, якщо вона більша за 1 (інакше null — ціна за одиницю).
     *
     * @param array<string, mixed> $variant
     */
    private static function minQuantity(array $variant): ?string
    {
        $min = (string) ($variant['min_quantity'] ?? '1');

        return 1 === bccomp($min, '1', 3) ? $min : null;
    }

    /** Ціна за $quantity одиниць у копійках, округлення до копійки (половина — вгору). */
    private static function times(int $minor, ?string $quantity): int
    {
        return null === $quantity ? $minor : (int) bcadd(bcmul((string) $minor, $quantity, 3), '0.5', 0);
    }

    private static function decimal(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private static function money(int $minor, string $currency): string
    {
        return \sprintf('%d.%02d %s', intdiv($minor, ListingFilterFactory::MINOR_PER_MAJOR), $minor % ListingFilterFactory::MINOR_PER_MAJOR, $currency);
    }

    /** GTIN-8/12/13/14 з валідною контрольною цифрою, інакше null (невалідний gtin Merchant відхиляє разом з товаром). */
    public static function gtin(?string $barcode): ?string
    {
        $code = preg_replace('/\s+/', '', (string) $barcode) ?? '';
        if (!preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return null;
        }
        $digits = array_map(intval(...), str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $d) {
            $sum += 0 === $i % 2 ? $d * 3 : $d;
        }

        return (10 - $sum % 10) % 10 === $check ? $code : null;
    }

    /** HTML опису → текст: без тегів, сутностей і зайвих пробілів. */
    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function g(XMLWriter $xml, string $name, string $value): void
    {
        $xml->startElementNs('g', $name, null);
        $xml->text(self::xmlText($value));
        $xml->endElement();
    }

    private static function element(XMLWriter $xml, string $name, string $value): void
    {
        $xml->writeElement($name, self::xmlText($value));
    }

    /**
     * Лише символи, дозволені в XML 1.0: XMLWriter::text() пропускає керівні (\x0B, \x1F з імпорту), і один такий символ
     * робить невалідним увесь фід. Невалідний UTF-8 — заміна на «?», потім вирізаються заборонені кодові точки.
     */
    public static function xmlText(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u', '', $value) ?? '';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Site\Feed;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Catalog\Import\Message\ImportCompleted;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Pricing\Promotion\PromotionProviderInterface;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Site\SiteName;
use App\Site\Catalog\ProductCards;
use App\Site\Catalog\ProductPresenter;
use App\Site\Feed\FeedChannel;
use App\Site\Feed\MerchantFeedGenerator;
use App\Site\Feed\MerchantFeedPaths;
use App\Site\Feed\MerchantFeeds;
use App\Site\Feed\Message\GenerateMerchantFeeds;
use App\Site\Feed\MessageHandler\GenerateMerchantFeedsHandler;
use App\Site\SiteUrlGenerator;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SimpleXMLElement;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Google Merchant feed на фікстурі каталогу: item = варіант, item_group_id, ціна/знижка/наявність/бренд/gtin/mpn/фото,
 * що не потрапляє у фід, окремий файл на мову.
 */
final class MerchantFeedGeneratorTest extends KernelTestCase
{
    private CatalogIndexFixture $fx;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(3));
        $this->fx = new CatalogIndexFixture($em, $this->suffix);
        $this->fx->v['V1']->setBarcode('4006381333931'); // валідний EAN-13
        $this->fx->v['V2']->setBarcode('4006381333932'); // контрольна цифра не та
        $this->fx->v['V1']->setPrice(Money::of(1000, 'UAH'), Money::of(1500, 'UAH'));
        $this->fx->v['V5']->setLeadTimeDays(5);
        $this->fx->p['P1']->translate('uk')->setDescription('<p>Легкі <b>бігові</b>&nbsp;кросівки</p>');
        $this->fx->p['P1']->translate('ru')->setName('P1 ru')->setSlug('p1-ru-'.$this->suffix);
        $em->flush();

        $db = self::getContainer()->get(Connection::class);
        foreach ([['p1-main.jpg', null], ['p1-v2.jpg', $this->fx->v['V2']->getId()]] as $i => [$path, $variant]) {
            $db->executeStatement('INSERT INTO catalog_media (path, mime_type, size_bytes, created_at) VALUES (?, ?, 1, NOW())', [$this->suffix.'-'.$path, 'image/jpeg']);
            $db->executeStatement('INSERT INTO catalog_product_media (product_id, media_id, variant_id, position) VALUES (?, ?, ?, ?)', [$this->fx->id('P1'), (int) $db->lastInsertId(), $variant, $i]);
        }
    }

    public function testItemsPerVariantWithPricesAvailabilityIdentifiersAndImages(): void
    {
        $result = self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk', 2);
        $items = $this->items($result['path']);

        $sku = fn (string $name): string => $name.'-'.$this->suffix;
        self::assertArrayNotHasKey($sku('V6'), $items, 'P4 вимкнено');
        self::assertArrayNotHasKey($sku('P5'), $items, 'ціна за запитом — не у фід');

        $v1 = $items[$sku('P1')]; // V1 — default-варіант P1, SKU картки
        self::assertSame('P'.$this->fx->id('P1'), $v1['item_group_id']);
        self::assertSame($v1['item_group_id'], $items[$sku('V2')]['item_group_id']);
        self::assertSame('P1, s42, red', $v1['title']);
        self::assertSame('Легкі бігові кросівки', $v1['description']);
        self::assertSame('15.00 UAH', $v1['price'], 'стара ціна — price…');
        self::assertSame('10.00 UAH', $v1['sale_price'], '…поточна — sale_price');
        self::assertSame('in_stock', $v1['availability']);
        self::assertSame('4006381333931', $v1['gtin']);
        self::assertSame('Nike '.$this->suffix, $v1['brand']);
        self::assertSame($sku('P1'), $v1['mpn']);
        self::assertSame('/p1-'.$this->suffix, parse_url($v1['link'], \PHP_URL_PATH), 'товар — /{slug} від кореня (адреси без /p/, ADR 2026-09-28)');
        self::assertStringEndsWith('/media/'.$this->suffix.'-p1-main.jpg', $v1['image_link']);
        self::assertSame('shoes > sneakers > running', $v1['product_type']);

        $v2 = $items[$sku('V2')];
        self::assertArrayNotHasKey('gtin', $v2, 'невалідна контрольна цифра');
        self::assertArrayNotHasKey('sale_price', $v2);
        self::assertStringEndsWith('-p1-v2.jpg', $v2['image_link'], 'фото варіанта першим…');
        self::assertStringEndsWith('-p1-main.jpg', $v2['additional_image_link'], '…фото картки — додатковим');

        self::assertSame('out_of_stock', $items[$sku('P3')]['availability'], 'V4: залишок 0');
        self::assertSame('backorder', $items[$sku('V5')]['availability'], 'під замовлення');
        self::assertSame((new DateTimeImmutable('today +5 days'))->format('Y-m-d'), substr($items[$sku('V5')]['availability_date'], 0, 10));
        self::assertArrayNotHasKey('item_group_id', $items[$sku('P6')], 'один варіант — без групи');
        self::assertSame('30.00 UAH', $items[$sku('P6')]['price']);
    }

    public function testOneFilePerLocaleOnlyCardsWithPageInThatLanguage(): void
    {
        $results = self::getContainer()->get(MerchantFeeds::class)->generate();
        self::assertNotNull($results);
        /** @var list<string> $locales */
        $locales = self::getContainer()->getParameter('app.locales');
        self::assertSame($locales, array_column($results, 'locale'));

        $ru = $this->items(self::getContainer()->get(MerchantFeedPaths::class)->file('ru'));
        self::assertArrayHasKey($this->fx->p['P1']->getVariants()->first()->getSku(), $ru, 'P1 має переклад і слаг ru');
        self::assertArrayNotHasKey('P6-'.$this->suffix, $ru, 'P6 без сторінки ru');
        self::assertSame('P1 ru, s42, red', $ru['P1-'.$this->suffix]['title']);
    }

    public function testCommandAndDispatchAfterImport(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('feed:merchant:generate'));
        $tester->execute(['--locale' => 'uk']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('google-merchant-uk.xml', $tester->getDisplay());

        // ImportCompleted — у загальну чергу async; його обробник лише ставить генерацію в окрему чергу feeds
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ImportCompleted(1));
        $async = self::getContainer()->get('messenger.transport.async');
        $feeds = self::getContainer()->get('messenger.transport.feeds');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertInstanceOf(InMemoryTransport::class, $feeds);
        $sent = $async->getSent();
        self::assertInstanceOf(ImportCompleted::class, end($sent)->getMessage());
        self::assertCount(0, $feeds->getSent());
        $asyncBefore = \count($sent);

        self::getContainer()->get(GenerateMerchantFeedsHandler::class)->onImport(new ImportCompleted(1));
        self::assertCount(1, $feeds->getSent());
        self::assertInstanceOf(GenerateMerchantFeeds::class, $feeds->getSent()[0]->getMessage());
        self::assertCount($asyncBefore, $async->getSent(), 'генерація фіду не займає async (листи, CRM)');
    }

    public function testSiteNameMapUsesFeedLocale(): void
    {
        $container = self::getContainer();
        $generator = new MerchantFeedGenerator(
            $container->get(Connection::class),
            $container->get(EntityManagerInterface::class),
            $container->get(Pricing::class),
            $container->get(PromotionProviderInterface::class),
            $container->get(Settings::class),
            $container->get(SiteUrlGenerator::class),
            $container->get(MerchantFeedPaths::class),
            new SiteName(['uk' => 'Магазин', 'en' => 'Shop'], 'uk', new RequestStack()),
            'shop.example.com',
        );
        /** @var list<string> $locales */
        $locales = $container->getParameter('app.locales');
        $other = array_values(array_diff($locales, ['uk']))[0];

        $uk = new SimpleXMLElement((string) file_get_contents($generator->generate('uk')['path']));
        self::assertSame('Магазин', (string) $uk->channel->title);
        $foreign = new SimpleXMLElement((string) file_get_contents($generator->generate($other)['path']));
        self::assertSame('en' === $other ? 'Shop' : 'Магазин', (string) $foreign->channel->title, 'нема назви цією мовою — мова за замовчуванням');
    }

    public function testControlCharactersDoNotBreakXml(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fx->p['P6']->translate('uk')->setName("P6\x0Bвертикальна\x1Fтабуляція\x00");
        $this->fx->p['P6']->translate('uk')->setDescription("<p>опис\x08з\x7F\x{1}сміттям</p>");
        $em->flush();

        $items = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk')['path']); // SimpleXML кидає на невалідному XML
        self::assertSame('P6вертикальнатабуляція', $items['P6-'.$this->suffix]['title']);
        self::assertStringStartsWith('опис', $items['P6-'.$this->suffix]['description']);
        self::assertSame("a\tb\nc", MerchantFeedGenerator::xmlText("a\tb\nc\x0C"));
        self::assertSame('ok?', str_replace("\u{FFFD}", '?', MerchantFeedGenerator::xmlText("ok\xFF")), 'невалідний UTF-8 — замінено, не зламано');
    }

    public function testDisabledVariantIsSkippedAndGroupFollowsActiveVariants(): void
    {
        $this->fx->v['V2']->setActive(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $items = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk')['path']);
        self::assertArrayNotHasKey('V2-'.$this->suffix, $items);
        self::assertArrayHasKey('P1-'.$this->suffix, $items);
        self::assertArrayNotHasKey('item_group_id', $items['P1-'.$this->suffix], 'лишився один активний варіант');
    }

    public function testVariantLinkOpensThatVariantWithSamePriceColorAndSize(): void
    {
        $items = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk')['path']);
        $v2 = $items['V2-'.$this->suffix];
        parse_str((string) parse_url($v2['link'], \PHP_URL_QUERY), $query);
        self::assertSame([$this->fx->color->getCode() => 'red', $this->fx->size->getCode() => 's38'], $query, 'осі варіанта в посиланні (ключі за алфавітом)');

        // сторінка товару з цими параметрами обирає саме V2 — ціна у фіді й на сторінці та сама
        $product = self::getContainer()->get(EntityManagerInterface::class)->find(Product::class, $this->fx->id('P1'));
        self::assertNotNull($product);
        $view = self::getContainer()->get(ProductPresenter::class)->present($product, 'uk', $query, null);
        self::assertSame($this->fx->v['V2']->getId(), $view['variant']?->getId());
        self::assertSame('12.00 UAH', $v2['price']);

        self::assertSame('red', $v2['color']);
        self::assertSame('s38', $v2['size']);
        self::assertArrayNotHasKey('color', $items['P6-'.$this->suffix], 'без осей — без g:color');
        self::assertNull(parse_url($items['P6-'.$this->suffix]['link'], \PHP_URL_QUERY), 'єдиний варіант — посилання без параметрів');
    }

    public function testMinimumQuantityPricesTheMinimalBatch(): void
    {
        $this->fx->p['P6']->getDefaultVariant()->setQuantityRules('10', '1');
        $this->fx->v['V5']->setUnit(SalesUnit::Meter);
        $this->fx->v['V5']->setQuantityRules('2.5', '0.5');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $items = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk')['path']);
        $p6 = $items['P6-'.$this->suffix];
        self::assertSame('300.00 UAH', $p6['price'], '30.00 × мінімум 10');
        self::assertSame('10', $p6['multipack']);
        $v5 = $items['V5-'.$this->suffix];
        self::assertSame('23.75 UAH', $v5['price'], '9.50 × 2.5 м');
        self::assertSame('2.5 m', $v5['unit_pricing_measure']);
        self::assertSame('1 m', $v5['unit_pricing_base_measure']);
        self::assertArrayNotHasKey('multipack', $items['V2-'.$this->suffix], 'мінімум 1 — ціна за одиницю');
    }

    public function testBusyLockSkipsAndOrphanTempsAreRemoved(): void
    {
        $paths = self::getContainer()->get(MerchantFeedPaths::class);
        $feeds = self::getContainer()->get(MerchantFeeds::class);
        $lock = self::getContainer()->get(LockFactory::class)->createLock(MerchantFeeds::LOCK);
        self::assertTrue($lock->acquire());
        try {
            self::assertNull($feeds->generate(['uk']), 'зайнято — пропуск, без очікування');
            $tester = new CommandTester((new Application(self::$kernel))->find('feed:merchant:generate'));
            self::assertSame(1, $tester->execute(['--locale' => 'uk']));
        } finally {
            $lock->release();
        }

        @mkdir(\dirname($paths->file('uk')), 0o775, true);
        $orphan = $paths->file('uk').'.deadbeef.tmp';
        file_put_contents($orphan, '<rss'); // процес убили посеред запису
        self::assertNotNull($feeds->generate(['uk']));
        self::assertFileDoesNotExist($orphan);
        self::assertSame([], $paths->orphanTemps());
    }

    public function testMetaFeedHasRequiredFieldsBrandFallbackAndOnlyMetaValues(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = self::getContainer()->get(Connection::class);
        foreach (['P2', 'P3', 'P6'] as $name) { // фото кожній картці: image_link у Meta обов'язковий
            $db->executeStatement('INSERT INTO catalog_media (path, mime_type, size_bytes, created_at) VALUES (?, ?, 1, NOW())', [$this->suffix.'-'.$name.'.jpg', 'image/jpeg']);
            $db->executeStatement('INSERT INTO catalog_product_media (product_id, media_id, variant_id, position) VALUES (?, ?, NULL, 0)', [$this->fx->id($name), (int) $db->lastInsertId()]);
        }
        $this->fx->p['P6']->setBrand(null);
        $this->fx->p['P2']->translate('uk')->setName(str_repeat('Довга назва ', 20)); // 240 символів + осі
        $this->fx->p['P2']->translate('uk')->setDescription(str_repeat('опис ', 1500)); // 7500 символів
        $this->fx->p['P3']->translate('uk')->setDescription(null)->setShortDescription(null);
        $em->flush();

        $result = self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk');
        $paths = self::getContainer()->get(MerchantFeedPaths::class);
        self::assertSame($paths->file('uk', FeedChannel::Meta), $result['paths']['meta']);
        self::assertStringEndsWith('/meta-uk.xml', $result['paths']['meta']);
        $meta = $this->items($result['paths']['meta']); // SimpleXML кидає на невалідному XML
        $google = $this->items($result['path']);
        self::assertSame(array_keys($google), array_keys($meta), 'ті самі item, той самий g:id в обох каналах');

        $sku = fn (string $name): string => $name.'-'.$this->suffix;
        foreach ([$sku('V6'), $sku('P5')] as $absent) {
            self::assertArrayNotHasKey($absent, $meta, 'вимкнений товар і «ціна за запитом» — не у фід');
        }
        foreach (array_filter($meta, fn (string $id): bool => str_ends_with($id, $this->suffix), \ARRAY_FILTER_USE_KEY) as $id => $item) {
            foreach (['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'] as $field) {
                self::assertNotSame('', $item[$field] ?? '', $id.': '.$field);
            }
            self::assertContains($item['availability'], ['in stock', 'out of stock'], $id);
            foreach (['availability_date', 'multipack', 'unit_pricing_measure', 'identifier_exists'] as $googleOnly) {
                self::assertArrayNotHasKey($googleOnly, $item, $id.': '.$googleOnly);
            }
            self::assertLessThanOrEqual(200, mb_strlen($item['title']));
            self::assertLessThanOrEqual(100, mb_strlen($item['brand']));
        }

        // brand: без бренду картки — назва магазину (Google такий товар позначає identifier_exists = no)
        self::assertSame(self::getContainer()->get(SiteName::class)->forLocale('uk'), $meta[$sku('P6')]['brand']);
        self::assertArrayNotHasKey('brand', $google[$sku('P6')]);
        self::assertSame('no', $google[$sku('P6')]['identifier_exists']);
        self::assertSame('Nike '.$this->suffix, $meta[$sku('P1')]['brand']);
        // опис: порожній — назва картки
        self::assertSame('P3, s40, red', $meta[$sku('P3')]['title']);
        self::assertSame('P3', $meta[$sku('P3')]['description']);
        // довжини за профілем каналу
        self::assertSame(200, mb_strlen($meta[$sku('P2')]['title']));
        self::assertSame(150, mb_strlen($google[$sku('P2')]['title']));
        self::assertSame(7499, mb_strlen($meta[$sku('P2')]['description']), 'Meta — до 9999 (пробіл у кінці обрізано)');
        self::assertSame(5000, mb_strlen($google[$sku('P2')]['description']));
        // варіанти — спільний item_group_id; під замовлення в Meta — in stock, нема залишку — out of stock
        self::assertSame('P'.$this->fx->id('P1'), $meta[$sku('P1')]['item_group_id']);
        self::assertSame($meta[$sku('P1')]['item_group_id'], $meta[$sku('V2')]['item_group_id']);
        self::assertSame('backorder', $google[$sku('V5')]['availability']);
        self::assertSame('in stock', $meta[$sku('V5')]['availability']);
        self::assertSame('out of stock', $meta[$sku('P3')]['availability']);
        self::assertSame($google[$sku('P1')]['price'], $meta[$sku('P1')]['price']);
        self::assertSame($google[$sku('P1')]['sale_price'], $meta[$sku('P1')]['sale_price']);

        // «бренд за замовчуванням» з налаштувань має перевагу над назвою магазину
        $settings = self::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::FEEDS_DEFAULT_BRAND, 'Власний бренд');
        try {
            $meta = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk', 500, null, [FeedChannel::Meta])['paths']['meta']);
            self::assertSame('Власний бренд', $meta[$sku('P6')]['brand']);
        } finally {
            $settings->invalidate();
        }
    }

    public function testPriceComesFromPricingWithPromotionWindowAndCustomLabels(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $promotion = new Promotion('Мінус 10', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->setEndsAt(new DateTimeImmutable('today +7 days 23:59'));
        $promotion->addProduct($this->fx->p['P6']);
        $em->persist($promotion);
        $em->flush();

        $result = self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk');
        $google = $this->items($result['path']);
        $meta = $this->items($result['paths']['meta']);
        $p6 = $google['P6-'.$this->suffix];
        self::assertSame('30.00 UAH', $p6['price'], 'ціна до акції — перекреслена');
        self::assertSame('27.00 UAH', $p6['sale_price'], 'Pricing: акція −10 %');
        [$start, $end] = explode('/', $p6['sale_price_effective_date']);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d\TH:i'), substr($start, 0, 16));
        self::assertSame((new DateTimeImmutable('today +7 days 23:59'))->format('Y-m-d\TH:iO'), $end);
        self::assertMatchesRegularExpression('~^\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d\d:\d\d/\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d\d:\d\d$~', $meta['P6-'.$this->suffix]['sale_price_effective_date'], 'Meta — зсув з двокрапкою');

        // та сама ціна, що на сторінці товару
        $product = $em->find(Product::class, $this->fx->id('P6'));
        self::assertNotNull($product);
        $view = self::getContainer()->get(ProductPresenter::class)->present($product, 'uk', [], null);
        self::assertSame('27.00', $view['price']?->toDecimalString());
        self::assertSame('30.00', $view['oldPrice']?->toDecimalString());

        // ручна стара ціна (V1) — sale_price без періоду; мітки: корінь категорії, бренд, акція
        $v1 = $google['P1-'.$this->suffix];
        self::assertArrayNotHasKey('sale_price_effective_date', $v1);
        self::assertSame('shoes', $v1['custom_label_0']);
        self::assertSame('Nike '.$this->suffix, $v1['custom_label_1']);
        self::assertSame('sale', $v1['custom_label_2']);
        self::assertSame('regular', $google['V2-'.$this->suffix]['custom_label_2']);
        self::assertSame('sale', $p6['custom_label_2']);
        self::assertSame($v1['custom_label_0'], $meta['P1-'.$this->suffix]['custom_label_0']);
    }

    public function testStockBelowMinimumQuantityIsOutOfStockInFeedAndOnPage(): void
    {
        $this->fx->v['V1']->setQuantityRules('10', '1'); // залишок 5 < мінімум 10: купити не можна
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame(ProductCards::OUT, ProductPresenter::availability($this->fx->v['V1']));

        $items = $this->items(self::getContainer()->get(MerchantFeedGenerator::class)->generate('uk')['path']);
        self::assertSame('out_of_stock', $items['P1-'.$this->suffix]['availability']);
        self::assertSame('in_stock', $items['V2-'.$this->suffix]['availability'], 'V2: 3 шт. при мінімумі 1');
    }

    public function testGtinChecksum(): void
    {
        self::assertSame('4006381333931', MerchantFeedGenerator::gtin(' 4006381333931 '));
        self::assertSame('96385074', MerchantFeedGenerator::gtin('96385074'));
        self::assertNull(MerchantFeedGenerator::gtin('4006381333932'));
        self::assertNull(MerchantFeedGenerator::gtin('ABC123'));
        self::assertNull(MerchantFeedGenerator::gtin(null));
    }

    /** @return array<string, array<string, string>> g:id → поле → значення (перше) */
    private function items(string $path): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents($path));
        $items = [];
        foreach ($xml->channel->item as $item) {
            $fields = ['title' => (string) $item->title, 'description' => (string) $item->description, 'link' => (string) $item->link];
            foreach ($item->children(MerchantFeedGenerator::NS) as $name => $value) {
                $fields[$name] ??= (string) $value;
            }
            $items[$fields['id']] = $fields;
        }

        return $items;
    }
}

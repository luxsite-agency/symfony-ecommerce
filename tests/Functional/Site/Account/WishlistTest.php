<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\ViewedProduct;
use App\Core\Customer\Entity\WishlistItem;
use App\Core\Customer\Viewed\RecentlyViewed;
use App\Core\Shared\Money\Money;
use App\Tests\Functional\Site\CatalogPageTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Обране й переглянуті (E12 PR-10): лише для залогіненого, в БД; кнопка без JS на картці й у лістингу; гостя веде на
 * вхід і нічого не пише; «переглянуті» — останні 20, поточна картка не показується.
 */
final class WishlistTest extends CatalogPageTestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->customer = new Customer('wish-'.$this->suffix.'@example.test', 'Покупець', self::defaultLocale());
        $this->customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->customer, 'correct horse battery'));
        $this->em()->persist($this->customer);
        $this->em()->flush();
    }

    public function testGuestIsSentToLoginAndNothingIsStored(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('.buy-box form.wish');
        self::assertCount(1, $form);
        self::assertSame('csrf-token', $form->filter('input[name="_token"]')->attr('value'), 'stateless CSRF — без сесії на картці');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'картка гостю без cookie');
        $this->client->submit($form->form());
        self::assertResponseRedirects('http://localhost/account/login');
        self::assertCount(0, $this->em()->getRepository(WishlistItem::class)->findAll());
        self::assertCount(0, $this->em()->getRepository(ViewedProduct::class)->findBy(['customer' => $this->customer]));
    }

    public function testCustomerAddsSeesStateInListingAndRemoves(): void
    {
        $locale = self::defaultLocale();
        $this->client->loginUser($this->customer, 'site');
        $p1 = $this->fx->p['P1'];
        $productUrl = $this->productUrl($p1, $locale);
        $crawler = $this->get($productUrl);
        self::assertCount(0, $crawler->filter('.buy-box form.wish-on'));
        $this->client->submit($crawler->filter('.buy-box form.wish')->form(), [], ['HTTP_REFERER' => 'http://localhost'.$productUrl]);
        self::assertResponseRedirects('http://localhost'.$productUrl, null, 'назад на картку');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.buy-box form.wish-on'), 'кнопка показує «в обраному»');
        self::assertSame('true', $crawler->filter('.buy-box form.wish-on button')->attr('aria-pressed'));
        self::assertCount(1, $this->em()->getRepository(WishlistItem::class)->findBy(['customer' => $this->customer]));

        // повторне додавання не дублює
        $this->client->request('POST', 'http://localhost/account/wishlist/add', ['product' => (string) $p1->getId(), '_token' => 'csrf-token']);
        self::assertResponseRedirects();
        self::assertCount(1, $this->em()->getRepository(WishlistItem::class)->findBy(['customer' => $this->customer]));

        // лістинг: лише картка P1 з позначкою
        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('li.card form.wish-on'));
        self::assertSame((string) $p1->getId(), $crawler->filter('li.card form.wish-on input[name="product"]')->attr('value'));
        self::assertGreaterThan(1, $crawler->filter('li.card form.wish')->count(), 'кнопка на кожній картці');

        // сторінка обраного в кабінеті
        $crawler = $this->get('http://localhost/account/wishlist');
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('li.card'));
        self::assertSame((string) $p1->getId(), $crawler->filter('li.card')->attr('data-product-id'));
        self::assertStringContainsString($locale.' P1', $crawler->filter('li.card h3')->text());
        $crawler = $this->get('http://localhost/account/');
        self::assertCount(1, $crawler->filter('a[href$="/account/wishlist"]'));

        // прибрати зі сторінки обраного — назад на неї, список порожній
        $crawler = $this->get('http://localhost/account/wishlist');
        $this->client->submit($crawler->filter('li.card form.wish-on')->form(), [], ['HTTP_REFERER' => 'http://localhost/account/wishlist']);
        self::assertResponseRedirects('http://localhost/account/wishlist');
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.empty');
        self::assertCount(0, $this->em()->getRepository(WishlistItem::class)->findBy(['customer' => $this->customer]));

        // чужий Referer не веде назовні
        $this->client->request('POST', 'http://localhost/account/wishlist/add', ['product' => (string) $p1->getId(), '_token' => 'csrf-token'], server: ['HTTP_REFERER' => 'http://localhost.evil.example/x']);
        self::assertResponseRedirects('/account/wishlist');
        // неактивний товар — 404
        $this->client->request('POST', 'http://localhost/account/wishlist/add', ['product' => (string) $this->fx->id('P4'), '_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRecentlyViewedBlockOnProductPage(): void
    {
        $locale = self::defaultLocale();
        $this->client->loginUser($this->customer, 'site');
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertCount(0, $crawler->filter('.viewed-products'), 'перший перегляд — показувати нічого');
        $this->get($this->productUrl($this->fx->p['P2'], $locale));
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertSame([(string) $this->fx->p['P2']->getId()], $crawler->filter('.viewed-products li.card')->each(static fn ($c): string => (string) $c->attr('data-product-id')), 'поточна картка не показується');
        $crawler = $this->get($this->productUrl($this->fx->p['P3'], $locale));
        self::assertSame(
            [(string) $this->fx->p['P1']->getId(), (string) $this->fx->p['P2']->getId()],
            $crawler->filter('.viewed-products li.card')->each(static fn ($c): string => (string) $c->attr('data-product-id')),
            'останні переглянуті першими, повторний перегляд оновлює час',
        );
        self::assertCount(3, $this->em()->getRepository(ViewedProduct::class)->findBy(['customer' => $this->customer]), 'один рядок на товар');
    }

    public function testRecentlyViewedKeepsLastTwenty(): void
    {
        $viewed = static::getContainer()->get(RecentlyViewed::class);
        $products = [];
        for ($i = 0; $i < RecentlyViewed::LIMIT + 3; ++$i) {
            $product = new Product('VIEW-'.$i.'-'.$this->suffix, 'UAH');
            $product->translate(self::defaultLocale())->setName('V'.$i)->setSlug('view-'.$i.'-'.$this->suffix);
            $product->getDefaultVariant()->setPrice(Money::of(100, 'UAH'));
            $this->em()->persist($product);
            $products[] = $product;
        }
        $this->em()->flush();
        foreach ($products as $product) {
            $viewed->record($this->customer, $product);
        }
        $ids = $viewed->productIds($this->customer);
        self::assertCount(RecentlyViewed::LIMIT, $ids);
        self::assertSame($products[RecentlyViewed::LIMIT + 2]->getId(), $ids[0], 'останній першим');
        self::assertNotContains($products[0]->getId(), $ids, 'найстаріші витіснено');
        self::assertNotContains($products[2]->getId(), $ids);
        self::assertContains($products[3]->getId(), $ids);
        self::assertCount(RecentlyViewed::LIMIT, $this->em()->getRepository(ViewedProduct::class)->findBy(['customer' => $this->customer]), 'зайві рядки видалено з БД');
    }
}

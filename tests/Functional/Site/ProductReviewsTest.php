<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Відгуки на картці товару й у кабінеті (рішення власника 13.10.2026): гість бачить лише «увійти», без cookie; покупець
 * подає з inline-форми → на модерацію → «Мої відгуки» зі статусом; схвалений — на картці з «Ім'я П.», «Купив цей товар»,
 * зірками в лістингу і в schema.org (лише справжні: демо — з позначкою, без schema); HTML і посилання відхиляються, текст
 * екранується; редагування повертає на модерацію; чужий відгук — 404; пагінація.
 */
final class ProductReviewsTest extends CatalogPageTestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->customer = $this->customer('rev', 'Олена Демо');
    }

    public function testGuestSeesSignInOnlyAndNoCookie(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $crawler = $this->get($this->productUrl($p1, $locale));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#reviews.product-reviews'));
        self::assertCount(0, $crawler->filter('form.review-form'), 'гостю форми нема');
        self::assertCount(1, $crawler->filter('.reviews-empty'));
        $login = $crawler->filter('.reviews-login a.btn:not(.btn-google)');
        self::assertCount(1, $login, 'звичайний вхід');
        self::assertStringEndsWith('/account/reviews/new?product='.$p1->getId(), (string) $login->attr('href'));
        // ключі Google у .env.test є: друга кнопка — вхід через Google з поверненням на форму відгуку (GoogleSignInAdapter, #74)
        $google = $crawler->filter('.reviews-login a.btn-google');
        self::assertCount(1, $google, 'кнопка Google');
        self::assertStringEndsWith('/account/google/start?next=/account/reviews/new?product%3D'.$p1->getId(), (string) $google->attr('href'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'картка гостю без cookie');
        self::assertNull(self::ld($crawler, 'Product')['aggregateRating'] ?? null, 'без відгуків — без aggregateRating');

        $this->client->request('GET', 'http://localhost'.$login->attr('href'));
        self::assertResponseRedirects('http://localhost/account/login', null, 'захищений маршрут веде на вхід, після входу — назад на форму');
        $this->client->request('POST', 'http://localhost/account/reviews/new', ['review' => ['product' => (string) $p1->getId(), 'rating' => '5', 'text' => 'гість', '_token' => 'csrf-token']]);
        self::assertResponseRedirects('http://localhost/account/login');
        self::assertCount(0, $this->em()->getRepository(ProductReview::class)->findAll());
    }

    public function testCustomerSubmitsModeratesAndSeesEverywhere(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $productUrl = $this->productUrl($p1, $locale);
        $this->client->loginUser($this->customer, 'site');

        $crawler = $this->get($productUrl);
        $form = $crawler->filter('form.review-form');
        self::assertCount(1, $form, 'покупцю — inline-форма');
        self::assertCount(0, $crawler->filter('.reviews-login'));
        $this->client->submit($form->form(), ['review[rating]' => '5', 'review[text]' => "Чудові кросівки <3\nБіг у них 100 км"]);
        self::assertResponseRedirects('http://localhost'.$productUrl.'#reviews');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'після перевірки');
        self::assertCount(1, $crawler->filter('.reviews-empty'), 'на модерації — на картці ще нема');
        self::assertSelectorTextContains('form.review-form h3', 'Ваш відгук');
        self::assertSelectorTextContains('form.review-form .review-status', 'На модерації');
        $review = $this->em()->getRepository(ProductReview::class)->findOneBy(['customer' => $this->customer]);
        self::assertNotNull($review);
        self::assertSame(ReviewStatus::Pending, $review->getStatus());
        self::assertSame("Чудові кросівки <3\nБіг у них 100 км", $review->getText(), 'текст як є: «<3» — не тег');
        self::assertFalse($review->isVerifiedPurchase());

        // кабінет: посилання, список зі статусом і редагуванням, no-store
        $crawler = $this->get('http://localhost/account/');
        self::assertCount(1, $crawler->filter('a[href$="/account/reviews"]'));
        $crawler = $this->get('http://localhost/account/reviews');
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('li.review.review-pending'));
        self::assertSelectorTextContains('.review-status', 'На модерації');
        self::assertCount(1, $crawler->filter('a[href$="/account/reviews/'.$review->getId().'"]'));

        // повторне подання з картки — редагування свого, не другий рядок
        $crawler = $this->get($productUrl);
        $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '4', 'review[text]' => 'Оновив думку']);
        self::assertResponseRedirects();
        self::assertSame(1, $this->em()->getRepository(ProductReview::class)->count(['customer' => $this->customer]));
        $review = $this->reload($review);
        self::assertSame(4, $review->getRating());

        // виконане замовлення з товаром → «купив», схвалення → видно на картці, у лістингу і в schema
        $order = $this->order($this->customer, $p1);
        $order->setStatus('completed');
        $this->em()->flush();
        $crawler = $this->get($productUrl);
        $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '4', 'review[text]' => 'Оновив думку']);
        $review = $this->reload($review);
        self::assertTrue($review->isVerifiedPurchase());
        static::getContainer()->get(ProductReviews::class)->approve($review, null);

        $crawler = $this->get($productUrl);
        $item = $crawler->filter('.product-reviews li.review');
        self::assertCount(1, $item);
        self::assertSame('Олена Д.', $item->filter('.review-author')->text(), 'ім’я та ініціал, без email');
        self::assertStringNotContainsString('@example.test', $crawler->filter('.product-reviews')->text());
        self::assertCount(1, $item->filter('.review-verified'));
        self::assertCount(0, $item->filter('.review-demo-badge'));
        self::assertCount(4, $item->filter('.rating-stars .star-on'));
        self::assertCount(1, $crawler->filter('.product-info .rating-link .rating'));
        self::assertSame('4,0 з 5 · 1 відгук', $crawler->filter('.product-reviews .reviews-summary-text')->text(), 'оцінка й кількість один раз');
        self::assertCount(0, $crawler->filter('.product-reviews .reviews-summary .rating-count'));
        $ld = self::ld($crawler, 'Product');
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4', 'reviewCount' => 1, 'bestRating' => '5', 'worstRating' => '1'], $ld['aggregateRating'] ?? null);
        self::assertSame('Олена Д.', $ld['review'][0]['author']['name'] ?? null);
        self::assertSame('Оновив думку', $ld['review'][0]['reviewBody'] ?? null);

        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertCount(1, $crawler->filter('li.card[data-product-id="'.$p1->getId().'"] .rating'), 'зірки в картці лістингу');
        self::assertCount(0, $crawler->filter('li.card[data-product-id="'.$this->fx->p['P2']->getId().'"] .rating'));

        // редагування з кабінету — назад на модерацію, зірки зникають
        $crawler = $this->get('http://localhost/account/reviews/'.$review->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Оновив думку', $crawler->filter('form.review-form textarea')->text());
        $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '5', 'review[text]' => 'Ще краще']);
        self::assertResponseRedirects('/account/reviews');
        $review = $this->reload($review);
        self::assertSame(ReviewStatus::Pending, $review->getStatus());
        self::assertNull(static::getContainer()->get(ProductRatings::class)->forProduct((int) $p1->getId()));
        $crawler = $this->get($productUrl);
        self::assertCount(0, $crawler->filter('.product-reviews li.review'));
        self::assertNull(self::ld($crawler, 'Product')['aggregateRating'] ?? null);

        // чужий відгук — 404
        $this->client->loginUser($this->customer('other', 'Інший Покупець'), 'site');
        $this->client->request('GET', 'http://localhost/account/reviews/'.$review->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', 'http://localhost/account/reviews/new', ['review' => ['product' => (string) $this->fx->id('P4'), 'rating' => '5', 'text' => 'вимкнений товар', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testHtmlAndLinksAreRejectedAndTextIsEscaped(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $this->client->loginUser($this->customer, 'site');
        $crawler = $this->get($this->productUrl($p1, $locale));
        $crawler = $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '5', 'review[text]' => '<script>alert(1)</script>']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form.review-form .form-error', 'HTML');
        self::assertCount(0, $this->em()->getRepository(ProductReview::class)->findAll());
        $crawler = $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '5', 'review[text]' => 'дешевше на https://evil.example/x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form.review-form .form-error', 'посилань');
        $this->client->request('POST', 'http://localhost/account/reviews/new', ['review' => ['product' => (string) $p1->getId(), 'rating' => '', 'text' => 'без оцінки', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em()->getRepository(ProductReview::class)->findAll());

        $text = 'Tom & Jerry: 5 < 7 > 3 "лапки" \'апостроф\'';
        $this->client->submit($crawler->filter('form.review-form')->form(), ['review[rating]' => '5', 'review[text]' => $text]);
        self::assertResponseRedirects();
        $review = $this->em()->getRepository(ProductReview::class)->findOneBy(['customer' => $this->customer]);
        self::assertSame($text, $review?->getText(), 'текст збережено без змін');
        static::getContainer()->get(ProductReviews::class)->approve($review, null);
        $crawler = $this->get($this->productUrl($p1, $locale));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Tom &amp; Jerry: 5 &lt; 7 &gt; 3 &quot;лапки&quot;', $html, 'екрановано');
        self::assertSame($text, $crawler->filter('.product-reviews .review-text')->text());
        self::assertSame($text, self::ld($crawler, 'Product')['review'][0]['reviewBody'] ?? null);
    }

    public function testDemoReviewIsLabelledAndKeptOutOfSchema(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $demo = new ProductReview($p1, $this->customer('demo', 'Олена Демо'), 5, 'Демо-відгук. Так виглядає схвалений відгук.', $locale);
        $demo->markDemo();
        $demo->moderate(ReviewStatus::Approved, null);
        $this->em()->persist($demo);
        $this->em()->flush();
        static::getContainer()->get(ProductRatings::class)->refresh((int) $p1->getId());

        $crawler = $this->get($this->productUrl($p1, $locale));
        self::assertCount(1, $crawler->filter('li.review.review-demo .review-demo-badge'));
        self::assertSelectorTextContains('li.review .review-demo-badge', 'Демо-відгук');
        self::assertCount(1, $crawler->filter('.product-info .rating-link'), 'на сторінці зірки з позначкою є');
        self::assertNull(self::ld($crawler, 'Product')['aggregateRating'] ?? null, 'у schema.org — лише справжні');
        self::assertArrayNotHasKey('review', self::ld($crawler, 'Product') ?? []);
    }

    public function testApprovedReviewsArePaginatedAndReplyShown(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $reviews = static::getContainer()->get(ProductReviews::class);
        $first = null;
        for ($i = 1; $i <= 11; ++$i) {
            $review = new ProductReview($p1, $this->customer('pg'.$i, 'Покупець '.$i), 1 + $i % 5, 'Відгук номер '.$i, $locale);
            $this->em()->persist($review);
            $this->em()->flush();
            $reviews->approve($review, null);
            $first ??= $review;
        }
        $reviews->reply($first, 'Дякуємо за відгук!', null);

        $crawler = $this->get($this->productUrl($p1, $locale));
        self::assertCount(10, $crawler->filter('.product-reviews li.review'), 'перша сторінка — 10');
        self::assertStringContainsString('Відгук номер 11', $crawler->filter('.product-reviews li.review')->first()->text(), 'нові зверху');
        self::assertCount(1, $crawler->filter('.reviews-pager'));
        self::assertStringContainsString('11 відгуків', $crawler->filter('.product-reviews .reviews-summary-text')->text());
        self::assertCount(10, self::ld($crawler, 'Product')['review'] ?? [], 'у schema — останні 10');

        $crawler = $this->get($this->productUrl($p1, $locale, ['reviews_page' => 2]));
        self::assertCount(1, $crawler->filter('.product-reviews li.review'));
        $item = $crawler->filter('.product-reviews li.review');
        self::assertStringContainsString('Відгук номер 1', $item->text());
        self::assertSelectorTextContains('.product-reviews li.review .review-reply', 'Дякуємо за відгук!');
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'));
        self::assertStringNotContainsString('reviews_page', (string) $crawler->filter('link[rel="canonical"]')->attr('href'), 'canonical без параметра сторінки відгуків');
    }

    /** Після HTTP-запиту тестовий EM скинуто: сутність з попереднього кроку відчеплена — перечитати з БД. */
    private function reload(ProductReview $review): ProductReview
    {
        if ($this->em()->contains($review)) {
            $this->em()->refresh($review);

            return $review;
        }

        return $this->em()->find(ProductReview::class, $review->getId()) ?? self::fail('відгук зник');
    }

    private function customer(string $tag, string $name): Customer
    {
        $customer = new Customer($tag.'-'.$this->suffix.'@example.test', $name, self::defaultLocale());
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function order(Customer $customer, Product $product): Order
    {
        // після HTTP-запитів фікстури відчеплені від тестового EM — керовані копії за id
        $customer = $this->em()->find(Customer::class, $customer->getId()) ?? self::fail('покупець зник');
        $product = $this->em()->find(Product::class, $product->getId()) ?? self::fail('товар зник');
        $order = new Order('T'.substr(bin2hex(random_bytes(6)), 0, 11), OrderSource::Checkout, self::defaultLocale(), 'Покупець', '+380501112233', 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $order->setCustomer($customer, null);
        $variant = $product->getDefaultVariant();
        $order->addItem(new OrderItem($order, $variant, $variant->getSku(), $variant->getPrice(), '1'));
        $order->recalculateTotals();
        $this->em()->persist($order);
        $this->em()->flush();

        return $order;
    }

    /** @return array<string, mixed>|null перший JSON-LD заданого @var */
    private static function ld(Crawler $crawler, string $type): ?array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $s): string => $s->text()) as $json) {
            $data = json_decode($json, true);
            if (\is_array($data) && $type === ($data['@type'] ?? null)) {
                return $data;
            }
        }

        return null;
    }
}

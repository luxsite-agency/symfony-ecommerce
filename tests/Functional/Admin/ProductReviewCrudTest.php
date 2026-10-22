<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Customer\Entity\Customer;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;

/**
 * Адмінка відгуків: пункт меню й лічильник нових на дашборді, список з фільтром стану, сторінка перегляду зі схваленням,
 * відхиленням і відповіддю магазину (CSRF), масові дії; редагування тексту покупця вимкнено; видалення перераховує агрегат.
 */
final class ProductReviewCrudTest extends AdminWebTestCase
{
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = bin2hex(random_bytes(3));
    }

    public function testModerationFlow(): void
    {
        $product = $this->product('REV-A');
        $pending = $this->review($product, $this->customer('a'), 5, 'Дуже добре');
        $pending2 = $this->review($this->product('REV-B'), $this->customer('b'), 2, 'Так собі');
        $user = $this->loginAs(UserRole::Manager, 'moderator@example.test');

        // дашборд: лічильник і список нових; меню
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        self::assertSame('2', $crawler->filter('[data-reviews-count]')->text());
        self::assertStringContainsString('Товар REV-A', $crawler->filter('#dashboard-reviews')->text());
        self::assertStringContainsString('Відгуки', $crawler->filter('#main-menu, .sidebar, .main-menu')->text());

        // список і фільтр стану
        $crawler = $this->client->request('GET', $this->adminUrl('/product-review'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Дуже добре', $crawler->filter('table')->text());
        self::assertCount(0, $crawler->filter('a[href*="/product-review/'.$pending->getId().'/edit"]'), 'текст покупця не редагується');
        $crawler = $this->client->request('GET', $this->adminUrl('/product-review?filters[status][comparison]==&filters[status][value]=approved'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Дуже добре', $crawler->filter('table')->text());

        // перегляд: схвалити
        $crawler = $this->client->request('GET', $this->adminUrl('/product-review/'.$pending->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Дуже добре', $crawler->text());
        $this->client->submit($crawler->filter('form.review-approve')->form());
        self::assertResponseRedirects();
        $pending = $this->reload($pending);
        self::assertSame(ReviewStatus::Approved, $pending->getStatus());
        self::assertSame($user->getDisplayName(), $pending->getModeratedBy());
        self::assertSame(5.0, static::getContainer()->get(ProductRatings::class)->forProduct((int) $product->getId())?->average(), 'агрегат оновлено');

        // відповідь магазину
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('form.review-approve'), 'схвалений — лише відхилити');
        $this->client->submit($crawler->filter('form.review-reply-form')->form(), ['reply' => 'Дякуємо, раді!']);
        self::assertResponseRedirects();
        $pending = $this->reload($pending);
        self::assertSame('Дякуємо, раді!', $pending->getReplyText());
        self::assertSame($user->getDisplayName(), $pending->getReplyBy());
        self::assertNotNull($pending->getRepliedAt());

        // CSRF на рішенні
        $this->client->request('POST', $this->adminUrl('/product-review/'.$pending->getId().'/moderate'), ['decision' => 'reject', 'token' => 'bad']);
        self::assertResponseStatusCodeSame(403);

        // масове відхилення
        $crawler = $this->client->request('GET', $this->adminUrl('/product-review'));
        $button = $crawler->filter('[data-action-name="batchReject"]');
        self::assertCount(1, $button);
        $this->client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => 'batchReject',
            'entityFqcn' => ProductReview::class,
            'batchActionUrl' => $button->attr('data-action-url'),
            'batchActionCsrfToken' => $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => [(string) $pending->getId(), (string) $pending2->getId()],
        ]);
        self::assertResponseRedirects();
        $pending = $this->reload($pending);
        $pending2 = $this->reload($pending2);
        self::assertSame(ReviewStatus::Rejected, $pending->getStatus());
        self::assertSame(ReviewStatus::Rejected, $pending2->getStatus());
        self::assertNull(static::getContainer()->get(ProductRatings::class)->forProduct((int) $product->getId()), 'відхилений — без зірок');
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertSame('0', $crawler->filter('[data-reviews-count]')->text());
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

    private function product(string $code): Product
    {
        $product = new Product($code.'-'.$this->suffix, 'UAH');
        $product->translate('uk')->setName('Товар '.$code)->setSlug(strtolower($code).'-'.$this->suffix);
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function customer(string $tag): Customer
    {
        $customer = new Customer($tag.'-'.$this->suffix.'@example.test', 'Олена Демо', 'uk');
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function review(Product $product, Customer $customer, int $rating, string $text): ProductReview
    {
        $review = new ProductReview($product, $customer, $rating, $text, 'uk');
        $this->em()->persist($review);
        $this->em()->flush();

        return $review;
    }
}

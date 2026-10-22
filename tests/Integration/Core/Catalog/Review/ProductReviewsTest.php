<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Review;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Catalog\Review\ReviewAccess;
use App\Core\Catalog\Review\ReviewLimitException;
use App\Core\Catalog\Review\ReviewSettings;
use App\Core\Customer\Auth\GoogleSignIn;
use App\Core\Customer\Entity\Customer;
use App\Core\Demo\DemoAccounts;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Сервіс відгуків: хто може писати (вимкнено / гість / require_google з демо-винятком), один відгук на товар з поверненням
 * на модерацію, добовий ліміт, «купив цей товар» лише з виконаного замовлення, агрегат оцінок (демо не йде в «справжні»),
 * стирання з акаунтом.
 */
final class ProductReviewsTest extends KernelTestCase
{
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->suffix = bin2hex(random_bytes(3));
    }

    public function testAccessMatrix(): void
    {
        $customer = $this->customer('acc');
        self::assertSame(ReviewAccess::Disabled, $this->service(enabled: false)->access($customer));
        self::assertSame(ReviewAccess::LoginRequired, $this->service()->access(null));
        self::assertSame(ReviewAccess::Allowed, $this->service()->access($customer));
        self::assertSame(ReviewAccess::GoogleRequired, $this->service(requireGoogle: true)->access($customer));
        self::assertSame(ReviewAccess::Allowed, $this->service(requireGoogle: true, linked: true)->access($customer), 'прив’язаний до Google');
        $demo = $this->customer('demo', DemoAccounts::RETAIL);
        self::assertSame(ReviewAccess::Allowed, $this->service(requireGoogle: true, demoPassword: 'demo-pass')->access($demo), 'демо-акаунт пише завжди');
        self::assertSame(ReviewAccess::GoogleRequired, $this->service(requireGoogle: true)->access($demo), 'демо-режим вимкнено — звичайний покупець');
    }

    public function testOneReviewPerProductBackToModerationAndRatings(): void
    {
        $product = $this->product('ONE');
        $customer = $this->customer('one');
        $ratings = self::getContainer()->get(ProductRatings::class);
        $service = $this->service();

        $review = $service->submit($product, $customer, 5, "  Чудово!\r\nРекомендую  ", 'uk');
        self::assertSame(ReviewStatus::Pending, $review->getStatus(), 'премодерація');
        self::assertSame("Чудово!\nРекомендую", $review->getText(), 'лише краї та CRLF');
        self::assertFalse($review->isVerifiedPurchase());
        self::assertNull($ratings->forProduct((int) $product->getId()), 'не схвалений — без агрегату');

        $service->approve($review, $this->user());
        $approved = $ratings->forProducts([(int) $product->getId()])[(int) $product->getId()] ?? null; // не forProduct(): PHPStan пам'ятає assertNull вище для того ж виразу
        self::assertNotNull($approved);
        self::assertSame(5.0, $approved->average());
        self::assertSame('Admin', $review->getModeratedBy());

        $again = $service->submit($product, $customer, 3, 'Передумав', 'ru');
        self::assertSame($review->getId(), $again->getId(), 'один відгук на товар: редагування свого');
        self::assertSame(ReviewStatus::Pending, $again->getStatus(), 'редагування повертає на модерацію');
        self::assertSame(3, $again->getRating());
        self::assertSame('ru', $again->getLocale());
        self::assertSame(1, $this->em()->getRepository(ProductReview::class)->count(['product' => $product]));
        self::assertNull($ratings->forProduct((int) $product->getId()), 'на модерації — зірки зникли');

        $auto = $this->service(premoderation: false)->submit($this->product('AUTO'), $customer, 4, 'Без премодерації', 'uk');
        self::assertSame(ReviewStatus::Approved, $auto->getStatus());
        self::assertSame(4.0, $ratings->forProduct((int) $auto->getProduct()->getId())?->average());
    }

    public function testDailyLimitCountsNewReviewsOnly(): void
    {
        $customer = $this->customer('lim');
        $service = $this->service(dailyLimit: 2);
        $p1 = $this->product('L1');
        $service->submit($p1, $customer, 5, 'раз', 'uk');
        $service->submit($this->product('L2'), $customer, 5, 'два', 'uk');
        $service->submit($p1, $customer, 4, 'редагування не рахується', 'uk');
        $this->expectException(ReviewLimitException::class);
        $service->submit($this->product('L3'), $customer, 5, 'три', 'uk');
    }

    public function testVerifiedPurchaseOnlyFromCompletedOrder(): void
    {
        $customer = $this->customer('vp');
        $product = $this->product('VP');
        $other = $this->product('VP2');
        $service = $this->service();
        $order = $this->order($customer, $product);
        self::assertFalse($service->hasCompletedOrderWith($customer, $product), 'нове замовлення — ще не купив');
        $order->setStatus('completed');
        $this->em()->flush();
        self::assertTrue($service->hasCompletedOrderWith($customer, $product));
        self::assertFalse($service->hasCompletedOrderWith($customer, $other), 'інший товар');
        self::assertFalse($service->hasCompletedOrderWith($this->customer('vp-other'), $product), 'інший покупець');
        self::assertTrue($service->submit($product, $customer, 5, 'купив', 'uk')->isVerifiedPurchase());
    }

    public function testRatingsAggregateExcludesDemoFromRealAndClearsWithAccount(): void
    {
        $product = $this->product('AGG');
        $ratings = self::getContainer()->get(ProductRatings::class);
        $service = $this->service(premoderation: false);
        $a = $this->customer('agg-a');
        $service->submit($product, $a, 5, 'п’ять', 'uk');
        $service->submit($product, $this->customer('agg-b'), 4, 'чотири', 'uk');
        $demo = new ProductReview($product, $this->customer('agg-demo'), 1, 'демо', 'uk');
        $demo->markDemo();
        $demo->moderate(ReviewStatus::Approved, null);
        $this->em()->persist($demo);
        $this->em()->flush();
        $ratings->refresh((int) $product->getId());

        $summary = $ratings->forProduct((int) $product->getId());
        self::assertNotNull($summary);
        self::assertSame([3, 10, 2, 9], [$summary->count, $summary->sum, $summary->realCount, $summary->realSum]);
        self::assertSame(3.3, $summary->average());
        self::assertSame(4.5, $summary->realAverage());
        self::assertSame(3, $summary->stars());

        $service->reject($demo, null);
        $afterReject = $ratings->forProduct((int) $product->getId());
        self::assertNotNull($afterReject);
        self::assertSame(2, $afterReject->count);

        $service->deleteAllOf($a);
        self::assertSame(0, $this->em()->getRepository(ProductReview::class)->count(['customer' => $a]));
        $afterDelete = $ratings->forProduct((int) $product->getId());
        self::assertNotNull($afterDelete, 'агрегат перераховано після стирання');
        self::assertSame(1, $afterDelete->count);
    }

    private function service(bool $enabled = true, bool $premoderation = true, bool $requireGoogle = false, int $dailyLimit = 5, bool $linked = false, string $demoPassword = ''): ProductReviews
    {
        $google = new class($linked) implements GoogleSignIn {
            public function __construct(private readonly bool $linked)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function isLinked(Customer $customer): bool
            {
                return $this->linked;
            }

            public function startPath(string $next, string $locale): ?string
            {
                return '' === $next ? null : '/account/google?next='.rawurlencode($next);
            }
        };

        return new ProductReviews(
            $this->em(),
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(ProductReviewRepository::class),
            self::getContainer()->get(ProductRatings::class),
            new ReviewSettings($enabled, $premoderation, $requireGoogle, $dailyLimit, 10),
            $google,
            new DemoAccounts($demoPassword),
        );
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function customer(string $tag, ?string $email = null): Customer
    {
        $customer = new Customer($email ?? $tag.'-'.$this->suffix.'@example.test', 'Олена Демо', 'uk');
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function product(string $code): Product
    {
        $product = new Product($code.'-'.$this->suffix, 'UAH');
        $product->translate('uk')->setName('Товар '.$code)->setSlug(strtolower($code).'-'.$this->suffix);
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $product->getDefaultVariant()->setStock('10');
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function user(): User
    {
        $user = new User('admin-'.$this->suffix.'@example.test', 'Admin', UserRole::Admin);
        $user->setPasswordHash('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function order(Customer $customer, Product $product): Order
    {
        $order = new Order('T'.substr(bin2hex(random_bytes(6)), 0, 11), OrderSource::Checkout, 'uk', 'Покупець', '+380501112233', 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $order->setCustomer($customer, null);
        $variant = $product->getDefaultVariant();
        $order->addItem(new OrderItem($order, $variant, $variant->getSku(), $variant->getPrice(), '1'));
        $order->recalculateTotals();
        $this->em()->persist($order);
        $this->em()->flush();

        return $order;
    }
}

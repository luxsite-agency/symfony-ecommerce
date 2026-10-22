<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Pricing;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Promotion\DoctrinePromotionProvider;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Рев'ю #50, п. A: провайдер джойнить customerGroups, тож allowsGroup не робить запит на акцію, а після $em->clear()
 * (патерн MerchantFeedGenerator, прайс-лист групи в Messenger) відв'язана акція не падає на ініціалізації колекції.
 */
final class DoctrinePromotionProviderTest extends KernelTestCase
{
    public function testDetachedPromotionStillKnowsItsGroupsWithoutQueries(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $wholesale = new CustomerGroup('wholesale_pp_'.bin2hex(random_bytes(2)), 'Опт');
        $product = new Product('PP-'.bin2hex(random_bytes(2)), 'UAH');
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $promotion = new Promotion('Опт −5 % '.bin2hex(random_bytes(2)), 'UAH');
        $promotion->setPercentDiscount('5');
        $promotion->setWholeCatalog(true); // цілі (products) порівнюються за ідентичністю — це наявний патерн фіду, не предмет тесту
        $promotion->addCustomerGroup($wholesale);
        foreach ([$wholesale, $product, $promotion] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $provider = new DoctrinePromotionProvider($em);
        $running = $provider->running(new DateTimeImmutable());
        $loaded = array_values(array_filter($running, static fn (Promotion $p): bool => $p->getId() === $promotion->getId()));
        self::assertCount(1, $loaded);
        $em->clear(); // як фід: сутності пакета відв'язано, кеш акцій лишився

        $group = $em->find(CustomerGroup::class, $wholesale->getId());
        $variant = $em->find(Product::class, $product->getId())?->getDefaultVariant();
        self::assertNotNull($group);
        self::assertNotNull($variant);
        $queries = self::queryCount($em);
        self::assertTrue($loaded[0]->allowsGroup($group), 'відв\'язана акція знає свої групи');
        self::assertFalse($loaded[0]->allowsGroup(new CustomerGroup('other', 'Інша')));
        self::assertTrue($loaded[0]->appliesTo($variant, $group));
        self::assertSame($queries, self::queryCount($em), 'без лінивих запитів');
    }

    private static function queryCount(EntityManagerInterface $em): int
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');

        return \count($holder->getData()['default'] ?? []);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Cart;

use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Cart\Exception\CartCreationRace;
use App\Core\Customer\CurrentCustomer;
use App\Core\Customer\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Гонка двох «У кошик» залогіненого без кошика (рев'ю #58 п. 1): другий запит упирається в unique customer_id і
 * отримує CartCreationRace з живим EntityManager, кошик покупця лишається один.
 */
final class CartResolverRaceTest extends KernelTestCase
{
    public function testSecondCreateForSameCustomerThrowsRaceAndKeepsSingleCart(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $customer = new Customer('race-'.bin2hex(random_bytes(3)).'@example.test', 'Покупець', 'uk');
        $em->persist($customer);
        $em->flush();
        $customerId = (int) $customer->getId();
        $current = new class($customerId) implements CurrentCustomer {
            public function __construct(private readonly int $id)
            {
            }

            public function customerId(): int
            {
                return $this->id;
            }
        };
        $resolver = new CartResolver($em, new RequestStack(), $current, 'UAH', self::getContainer()->get(ManagerRegistry::class));
        self::assertNull($resolver->current(), 'кошика ще нема');

        // «паралельний» запит встиг створити кошик покупця між читанням і записом
        $winner = new Cart(Cart::hashToken(bin2hex(random_bytes(32))), 'UAH');
        $winner->assignTo($customer);
        $em->persist($winner);
        $em->flush();
        $winnerId = (int) $winner->getId();

        try {
            $resolver->currentOrCreate();
            self::fail('очікувалась CartCreationRace');
        } catch (CartCreationRace $e) {
            self::assertSame($customerId, $e->customerId);
        }
        self::assertTrue($em->isOpen(), 'EntityManager скинуто, а не лишено закритим');
        $seen = (new CartResolver($em, new RequestStack(), $current, 'UAH', self::getContainer()->get(ManagerRegistry::class)))->current();
        self::assertNotNull($seen, 'наступний запит бачить кошик переможця');
        self::assertSame($winnerId, $seen->getId());
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM cart WHERE customer_id = ?', [$customerId]));
    }
}

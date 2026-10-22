<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Спільні фікстури пакетів змін: менеджер, дві категорії, товар ML-14 (4262.00 UAH, uk-переклад), сторінка, група wholesale. */
abstract class ChangesetTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Connection $db;
    protected int $userId;
    protected int $doorsId;
    protected int $handlesId;
    protected int $productId;
    protected int $variantId;
    protected int $pageId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();

        $user = new User('manager@example.com', 'Олена', UserRole::Manager);
        $doors = new Category();
        $doors->translate('uk')->setName('Двері')->setSlug('dveri');
        $handles = new Category();
        $handles->translate('uk')->setName('Ручки')->setSlug('ruchky');
        $product = new Product('ML-14', 'UAH');
        $product->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14')->setDescription('<p>Опис</p>');
        $product->addCategory($doors);
        $product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $page = new Page();
        $page->translate('uk')->setTitle('Доставка')->setSlug('dostavka')->setBody('<p>Текст</p>');
        $group = new CustomerGroup('wholesale', 'Опт');
        foreach ([$user, $doors, $handles, $product, $page, $group] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->userId = (int) $user->getId();
        $this->doorsId = (int) $doors->getId();
        $this->handlesId = (int) $handles->getId();
        $this->productId = (int) $product->getId();
        $this->variantId = (int) $product->getDefaultVariant()->getId();
        $this->pageId = (int) $page->getId();
        $this->em->clear();
        // створення товару поставило ReindexProducts у чергу — тести рахують лише те, що додає пакет
        $this->asyncTransport()->reset();
    }

    protected function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    protected function user(): User
    {
        $user = $this->em->find(User::class, $this->userId);
        self::assertNotNull($user);

        return $user;
    }

    protected function product(): Product
    {
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->productId);
        self::assertNotNull($product);

        return $product;
    }

    /** @return list<array<string, mixed>> */
    protected function auditRows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM admin_audit_entry ORDER BY id');
    }

    /** @return array<string, mixed> */
    protected static function priceOp(int|string $variant, string $price, ?string $old = null, string $expectPrice = '4262.00', ?string $expectOld = null): array
    {
        return ['op' => 'set_variant_price', 'variant' => $variant, 'price' => $price, 'old_price' => $old, 'expect' => ['price' => $expectPrice, 'old_price' => $expectOld]];
    }
}

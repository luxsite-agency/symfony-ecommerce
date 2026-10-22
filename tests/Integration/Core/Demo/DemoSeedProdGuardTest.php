<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Demo;

use App\Core\Catalog\Entity\Product;
use App\Core\Demo\Command\DemoSeedCommand;
use App\Core\Demo\DemoPurger;
use App\Core\Demo\DemoSeeder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Запобіжники prod: без --force-prod команда нічого не робить; з ним — не наповнює каталог, де вже є не-демо товари.
 * Команда збирається з сервісів контейнера, але з середовищем «prod» (ядро тестове). Відмова — до будь-якого запису,
 * тож DAMA-відкату досить.
 */
final class DemoSeedProdGuardTest extends KernelTestCase
{
    private Connection $db;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->output = new BufferedOutput();
    }

    public function testProdWithoutForceProdRefusesBothSeedAndPurge(): void
    {
        self::assertSame(Command::FAILURE, $this->invoke(purge: false, forceProd: false));
        self::assertStringContainsString('--force-prod', $this->output->fetch());
        self::assertSame(Command::FAILURE, $this->invoke(purge: true, forceProd: false));
        self::assertSame(0, $this->demoProducts());
    }

    public function testProdRefusesToMixDemoIntoARealCatalog(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Product('REAL-GUARD-1', 'UAH'));
        $em->flush();

        self::assertSame(Command::FAILURE, $this->invoke(purge: false, forceProd: true));
        self::assertStringContainsString('не-демо товар', $this->output->fetch());
        self::assertSame(0, $this->demoProducts());
    }

    private function invoke(bool $purge, bool $forceProd): int
    {
        $container = self::getContainer();
        $command = new DemoSeedCommand($container->get(DemoSeeder::class), $container->get(DemoPurger::class), $this->db, 'prod');

        return $command(new SymfonyStyle(new ArrayInput([]), $this->output), purge: $purge, forceProd: $forceProd);
    }

    private function demoProducts(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_product_variant WHERE sku LIKE 'DEMO-%'");
    }
}

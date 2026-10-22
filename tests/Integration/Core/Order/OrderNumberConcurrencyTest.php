<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Консиліум PR #6 п. 2: 4 процеси × 25 номерів одного періоду паралельно. Стара реалізація (INSERT IGNORE +
 * SELECT … FOR UPDATE) на MySQL 8.0 давала ERROR 1213 Deadlock у кожній парі одночасних оформлень; нова —
 * один атомарний upsert — дає 100 різних послідовних номерів без помилок. Процеси пишуть у БД тестів повз DAMA
 * (справжні коміти), тому рядок лічильника періоду 9912 прибирається до і після.
 */
final class OrderNumberConcurrencyTest extends KernelTestCase
{
    private const int WORKERS = 4;
    private const int PER_WORKER = 25;

    private Connection $raw;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $kernelConnection */
        $kernelConnection = self::getContainer()->get(Connection::class);
        // лише параметри драйвера: без DAMA-обгортки й url — окреме з'єднання зі справжніми комітами
        $params = array_intersect_key($kernelConnection->getParams(), array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'charset', 'serverVersion', 'unix_socket']));
        $this->raw = DriverManager::getConnection($params);
        $this->raw->executeStatement("DELETE FROM sales_order_number_counter WHERE period = '9912'");
    }

    protected function tearDown(): void
    {
        $this->raw->executeStatement("DELETE FROM sales_order_number_counter WHERE period = '9912'");
        $this->raw->close();
        parent::tearDown();
    }

    public function testParallelProcessesGetUniqueNumbersWithoutDeadlock(): void
    {
        $env = ['ORDER_NUMBER_DB' => json_encode($this->raw->getParams(), \JSON_THROW_ON_ERROR)];
        $processes = [];
        for ($i = 0; $i < self::WORKERS; ++$i) {
            $process = new Process([\PHP_BINARY, __DIR__.'/fixtures/order_number_worker.php', (string) self::PER_WORKER], null, $env, null, 60);
            $process->start();
            $processes[] = $process;
        }

        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), 'Процес нумерації впав: '.$process->getErrorOutput());
            $numbers = [...$numbers, ...array_filter(explode("\n", trim($process->getOutput())))];
        }

        $expected = self::WORKERS * self::PER_WORKER;
        self::assertCount($expected, $numbers);
        self::assertCount($expected, array_unique($numbers), 'Дубльовані номери: '.implode(', ', array_diff_key($numbers, array_unique($numbers))));
        $seq = array_map(static fn (string $n): int => (int) substr($n, 5), $numbers);
        sort($seq);
        self::assertSame(range(1, $expected), $seq, 'Номери періоду 9912 — суцільна послідовність');
        self::assertSame($expected, (int) $this->raw->fetchOne("SELECT last FROM sales_order_number_counter WHERE period = '9912'"));
    }
}

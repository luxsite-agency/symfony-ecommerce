<?php

declare(strict_types=1);

/*
 * Робочий процес тесту конкурентності номерів: N номерів для періоду 9912, кожен у своїй транзакції.
 * Параметри з'єднання — JSON у змінній оточення ORDER_NUMBER_DB (без DAMA: справжні коміти в БД тестів).
 */

use App\Core\Order\OrderNumberGenerator;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 5).'/vendor/autoload.php';

/** @var array<string, mixed> $params */
$params = json_decode((string) getenv('ORDER_NUMBER_DB'), true, 512, \JSON_THROW_ON_ERROR);
$count = (int) ($argv[1] ?? 25);
$connection = DriverManager::getConnection($params);
$generator = new OrderNumberGenerator($connection);
$period = new DateTimeImmutable('2099-12-15');

for ($i = 0; $i < $count; ++$i) {
    $connection->beginTransaction();
    try {
        $number = $generator->next($period);
        // затримка всередині транзакції, щоб процеси реально перетиналися на рядку лічильника
        usleep(random_int(0, 2000));
        $connection->commit();
    } catch (Throwable $e) {
        $connection->rollBack();
        fwrite(\STDERR, $e::class.': '.$e->getMessage()."\n");
        exit(1);
    }
    echo $number, "\n";
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Security;

use Doctrine\DBAL\Connection;
use PDO;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\SessionHandlerFactory;

/**
 * Сесії в MySQL (E12 PR-0): обробник з DATABASE_URL, таблиця з міграції підходить PdoSessionHandler (запис, читання,
 * gc, знищення), TTL і gc з framework.yaml. У тестах сховище — mock_file (framework.test), тому обробник збирається
 * тією ж фабрикою, що й у prod; свої комміти (окреме PDO-з'єднання) — рядок прибирається.
 */
final class SessionStorageTest extends KernelTestCase
{
    public function testSessionsLiveInMysqlWithTtlAndGc(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        // DATABASE_URL — як у prod, але в тестах Doctrine додає суфікс БД (shop_test): обробник — у ту саму БД
        $dsn = (string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL'));
        $dsn = (string) preg_replace('~^(mysql://[^/]+)/[^?]*~', '$1/'.$db->getDatabase(), $dsn);
        $handler = SessionHandlerFactory::createHandler($dsn);
        self::assertInstanceOf(PdoSessionHandler::class, $handler);

        /** @var array<string, mixed> $options */
        $options = self::getContainer()->getParameter('session.storage.options');
        self::assertSame(86400, (int) $options['gc_maxlifetime']);
        self::assertSame(1, (int) $options['gc_probability']);
        self::assertSame(100, (int) $options['gc_divisor']);

        // свої комміти обробника бачить лише з'єднання поза транзакцією DAMA
        $params = $db->getParams();
        $pdo = new PDO(\sprintf('mysql:host=%s;port=%d;dbname=%s', $params['host'] ?? '', $params['port'] ?? 3306, $db->getDatabase()), (string) ($params['user'] ?? ''), (string) ($params['password'] ?? ''));
        $row = static function (string $id) use ($pdo): ?array {
            $st = $pdo->prepare('SELECT sess_lifetime - sess_time AS ttl FROM sessions WHERE sess_id = ?');
            $st->execute([$id]);

            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };

        $id = 'pr0test'.bin2hex(random_bytes(12));
        $ini = \ini_get('session.gc_maxlifetime');
        ini_set('session.gc_maxlifetime', (string) $options['gc_maxlifetime']); // так робить NativeSessionStorage з цих опцій
        try {
            self::assertTrue($handler->open('', 'sid'));
            self::assertSame('', $handler->read($id), 'нова сесія');
            self::assertTrue($handler->write($id, 'a|s:1:"b";'));
            $handler->close();
            self::assertSame(86400, (int) ($row($id)['ttl'] ?? 0), 'рядок живе gc_maxlifetime');

            $again = SessionHandlerFactory::createHandler($dsn);
            $again->open('', 'sid');
            self::assertSame('a|s:1:"b";', $again->read($id), 'пережила «деплой»: новий обробник читає з БД');
            $again->close();

            // протухла сесія прибирається gc при закритті
            $pdo->prepare('UPDATE sessions SET sess_lifetime = UNIX_TIMESTAMP() - 1 WHERE sess_id = ?')->execute([$id]);
            $again->open('', 'sid');
            self::assertNotFalse($again->gc(86400));
            $again->close();
            self::assertNull($row($id));
        } finally {
            $pdo->prepare('DELETE FROM sessions WHERE sess_id = ?')->execute([$id]);
            ini_set('session.gc_maxlifetime', (string) $ini);
        }
    }
}

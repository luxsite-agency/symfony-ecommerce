<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Core\Catalog\Import\Message\ProcessImportChunk;
use App\Core\Catalog\Import\MessageHandler\ProcessImportChunkHandler;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\MessageHandler\PushOrderToCrmHandler;
use App\Core\Order\MessageHandler\SendCustomerOrderEmailHandler;
use App\Core\Order\MessageHandler\SendShopOrderEmailHandler;
use App\Core\Seo\Message\PruneNotFoundLog;
use App\Core\Seo\MessageHandler\PruneNotFoundLogHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Консиліум PR #6 п. 1 і п. 6: хендлери outbox-повідомлень справді зареєстровані в шині (services.yaml виключає
 * каталоги Message/, тож хендлер у Message/ мовчки не є сервісом і воркер кидає NoHandlerForMessageException),
 * а таблиця транспорту doctrine:// існує після міграцій (auto_setup=0 — сама не створиться).
 */
final class MessengerWiringTest extends KernelTestCase
{
    public function testEveryOutboxMessageHasARegisteredHandler(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('debug:messenger'));
        $tester->execute([]);
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        foreach ([
            ProcessImportChunk::class => ProcessImportChunkHandler::class,
            PushOrderToCrm::class => PushOrderToCrmHandler::class,
            SendCustomerOrderEmail::class => SendCustomerOrderEmailHandler::class,
            SendShopOrderEmail::class => SendShopOrderEmailHandler::class,
            PruneNotFoundLog::class => PruneNotFoundLogHandler::class,
        ] as $message => $handler) {
            self::assertStringContainsString($message.' handled by '.$handler, $output, \sprintf('%s без хендлера в шині', $message));
        }
    }

    public function testMessengerMessagesTableIsCreatedByMigrations(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $columns = array_column($connection->fetchAllAssociative('SHOW COLUMNS FROM messenger_messages'), 'Field');
        self::assertSame(['id', 'body', 'headers', 'queue_name', 'created_at', 'available_at', 'delivered_at'], $columns);
    }
}

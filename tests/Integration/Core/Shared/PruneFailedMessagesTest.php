<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Shared;

use App\Core\Shared\Messenger\Message\PruneFailedMessages;
use App\Core\Shared\Messenger\MessageHandler\PruneFailedMessagesHandler;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Строк зберігання черги failed (консиліум безпеки 10.10.2026, п. 10): старша за app.messenger_failed_days видаляється,
 * свіжа й інші черги лишаються. Дані покупців і IP замовлень не анонімізуються (рішення власника 10.10.2026).
 */
final class PruneFailedMessagesTest extends KernelTestCase
{
    public function testFailedQueueOlderThanRetentionIsPruned(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $marker = 'retention-'.bin2hex(random_bytes(4));
        $token = '{"type":"App\\Core\\Customer\\Message\\SendCustomerAuthMail"}';
        $crm = '{"type":"App\\Core\\Integration\\Crm\\Message\\PushOrderToCrm"}';
        foreach ([['failed', '-8 days', $token, 'old-token'], ['failed', '-6 days', $token, 'fresh-token'], ['failed', '-40 days', $crm, 'old-crm'], ['auth_mail', '-30 days', $token, 'other-queue']] as [$queue, $age, $headers, $tag]) {
            $db->insert('messenger_messages', ['body' => $marker.' '.$tag, 'headers' => $headers, 'queue_name' => $queue, 'created_at' => (new DateTimeImmutable($age, new DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'available_at' => (new DateTimeImmutable($age))->format('Y-m-d H:i:s')]);
        }
        $deleted = (new PruneFailedMessagesHandler($db, 7))(new PruneFailedMessages());
        self::assertSame(1, $deleted, 'лише старий лист з токеном');
        $left = $db->fetchFirstColumn('SELECT body FROM messenger_messages WHERE body LIKE ? ORDER BY body', [$marker.'%']);
        self::assertSame([$marker.' fresh-token', $marker.' old-crm', $marker.' other-queue'], $left, 'свіжий токен, замовлення в CRM (навіть старе) і інші черги лишаються');
        $db->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', [$marker.'%']);
    }
}

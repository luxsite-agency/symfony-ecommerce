<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Audit;

use App\Core\Audit\AdminAuditLog;
use App\Core\Audit\Message\PruneAdminAudit;
use App\Core\Audit\MessageHandler\PruneAdminAuditHandler;
use App\Schedule;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

/** Журнал змін прибирається за app.admin_audit_months щоночі зі Scheduler; 0 = не прибирати. */
final class PruneAdminAuditTest extends KernelTestCase
{
    public function testPruneKeepsRecentRows(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO admin_audit_entry (occurred_at, source, action, entity_type, entity_id) VALUES (NOW() - INTERVAL 25 MONTH, 'admin_ui', 'update', 'product', 1), (NOW() - INTERVAL 1 DAY, 'admin_ui', 'update', 'product', 1)");

        (new PruneAdminAuditHandler(self::getContainer()->get(AdminAuditLog::class)))(new PruneAdminAudit());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry'));

        self::assertSame(0, (new AdminAuditLog($db, 0))->prune(), '0 = зберігання вимкнене');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry'));
    }

    /** Рев'ю C #68 L5: видалення пакетами — кілька DELETE … LIMIT, поки є що прибирати. */
    public function testPruneDeletesInBatches(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM admin_audit_entry');
        for ($i = 0; $i < 5; ++$i) {
            $db->executeStatement("INSERT INTO admin_audit_entry (occurred_at, source, action, entity_type, entity_id) VALUES (NOW() - INTERVAL 30 MONTH, 'admin_ui', 'update', 'product', 1)");
        }
        $db->executeStatement("INSERT INTO admin_audit_entry (occurred_at, source, action, entity_type, entity_id) VALUES (NOW(), 'admin_ui', 'update', 'product', 1)");

        self::assertSame(5, (new AdminAuditLog($db, 24, pruneBatch: 2))->prune());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry'));
    }

    public function testPruneIsScheduledNightly(): void
    {
        self::bootKernel();
        $found = [];
        foreach (self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages() as $m) {
            foreach ($m->getMessages(new MessageContext('default', $m->getId(), $m->getTrigger(), new DateTimeImmutable())) as $message) {
                if ($message instanceof PruneAdminAudit) {
                    $found[] = (string) $m->getTrigger();
                }
            }
        }
        self::assertSame(['40 3 * * *'], $found);
    }
}

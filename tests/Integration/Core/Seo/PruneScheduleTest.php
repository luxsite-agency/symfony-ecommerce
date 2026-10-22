<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Seo;

use App\Core\Seo\Message\PruneNotFoundLog;
use App\Schedule;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

/** Журнал 404 прибирається щоночі зі Scheduler, повідомлення має хендлер (рев'ю #37). */
final class PruneScheduleTest extends KernelTestCase
{
    public function testPruneIsScheduledNightly(): void
    {
        self::bootKernel();
        $found = [];
        foreach (self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages() as $m) {
            foreach ($m->getMessages(new MessageContext('default', $m->getId(), $m->getTrigger(), new DateTimeImmutable())) as $message) {
                if ($message instanceof PruneNotFoundLog) {
                    $found[] = (string) $m->getTrigger();
                }
            }
        }
        self::assertSame(['45 3 * * *'], $found);
    }
}

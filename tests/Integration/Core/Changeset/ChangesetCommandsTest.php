<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Changeset\ChangesetStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** changeset:dry-run <json> і changeset:apply <id> --user (сценарії з CSV, тести без адмінки). */
final class ChangesetCommandsTest extends ChangesetTestCase
{
    public function testDryRunSaveAndApplyFromJsonFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cs');
        self::assertNotFalse($file);
        file_put_contents($file, json_encode(['title' => 'З файлу', 'operations' => [
            self::priceOp('ML-14', '3990.00', '4262.00'),
            ['op' => 'set_translation', 'entity' => 'page', 'id' => $this->pageId, 'locale' => 'uk', 'fields' => ['body' => '<p>Нове</p>'], 'expect' => ['body' => '<p>Текст</p>']],
        ]], \JSON_UNESCAPED_UNICODE));

        $dryRun = $this->tester('changeset:dry-run');
        self::assertSame(0, $dryRun->execute(['file' => $file]));
        $out = json_decode($dryRun->getDisplay(), true);
        self::assertSame([], $out['errors']);
        self::assertCount(2, $out['diff']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_changeset'), 'Без --save нічого не зберігається');

        self::assertSame(Command::INVALID, $dryRun->execute(['file' => $file, '--save' => true]), 'INVALID без --user');
        self::assertStringContainsString('--user', $dryRun->getDisplay());
        self::assertSame(0, $dryRun->execute(['file' => $file, '--save' => true, '--user' => 'Manager@Example.com']));
        $saved = json_decode($dryRun->getDisplay(), true);
        self::assertSame('proposed', $saved['status']);
        self::assertSame('cli', $this->db->fetchOne('SELECT source FROM admin_changeset WHERE id = ?', [$saved['id']]));

        $apply = $this->tester('changeset:apply');
        self::assertSame(Command::INVALID, $apply->execute(['id' => (string) $saved['id']]), 'INVALID без --user');
        self::assertSame(0, $apply->execute(['id' => (string) $saved['id'], '--user' => 'manager@example.com']));
        self::assertStringContainsString(ChangesetStatus::Applied->label(), $apply->getDisplay());
        self::assertSame(399_000, $this->product()->getDefaultVariant()->getPrice()->amount);
        self::assertSame(2, $apply->execute(['id' => (string) $saved['id'], '--user' => 'manager@example.com']), 'Повторно — відмова з JSON');
        self::assertSame('not_applicable', json_decode($apply->getDisplay(), true)['error']);
        unlink($file);
    }

    public function testDryRunReportsErrorsWithExitCode2(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cs');
        self::assertNotFalse($file);
        file_put_contents($file, json_encode(['title' => 'Помилки', 'operations' => [self::priceOp('NO-SUCH', '1.00')]]));
        $dryRun = $this->tester('changeset:dry-run');
        self::assertSame(2, $dryRun->execute(['file' => $file]));
        self::assertSame('not_found', json_decode($dryRun->getDisplay(), true)['errors'][0]['code']);

        file_put_contents($file, '{not json');
        self::assertSame(2, $dryRun->execute(['file' => $file]));
        self::assertStringContainsString('Не JSON', $dryRun->getDisplay());
        unlink($file);
    }

    private function tester(string $command): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find($command));
    }
}

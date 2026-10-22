<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Shared;

use App\Core\Settings\SecretState;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\Command\SecretsRotateCommand;
use App\Core\Shared\Crypto\SecretBox;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** M1 рев'ю #70: ротація SETTINGS_SECRET_KEY — перешифрувати всі секрети старим → новим; нечитні пропустити або стерти. */
final class SecretsRotateCommandTest extends KernelTestCase
{
    private const string SECRET = 'sk-or-v1-rotate-me-please-4242';

    private Settings $settings;
    private Connection $db;
    /** @var list<string> */
    private array $envSet = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(Settings::class);
        $this->db = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->envSet as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        parent::tearDown();
    }

    private function env(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $this->envSet[] = $name;
    }

    /** @param array<string, string|bool> $args */
    private function rotate(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:secrets:rotate'));
        $tester->execute($args);

        return $tester;
    }

    private function stored(): string
    {
        return (string) $this->db->fetchOne('SELECT value FROM setting WHERE code = ?', [SettingKeys::AI_OPENROUTER_KEY]);
    }

    public function testRotatesToNewKeyFromEnvAndOldKeyNoLongerOpens(): void
    {
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, self::SECRET);
        $before = $this->stored();
        $newKey = SecretBox::generateKey();
        $this->env(SecretsRotateCommand::NEW_KEY_ENV, $newKey);

        $dry = $this->rotate(['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertSame($before, $this->stored(), 'dry-run нічого не міняє');

        $tester = $this->rotate();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Перешифровано 1, не розшифрувати 0, порожніх 1', $tester->getDisplay());
        self::assertStringContainsString('записати новий ключ у .env.local', $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        self::assertStringNotContainsString($newKey, $tester->getDisplay());

        $after = $this->stored();
        self::assertNotSame($before, $after);
        self::assertTrue(SecretBox::isEnvelope($after));
        self::assertSame(self::SECRET, (new SecretBox($newKey))->decrypt($after));
        // чинний ключ застосунку ще старий — для нього конверт тепер нечитний, не 500
        $this->settings->invalidate();
        self::assertSame(SecretState::Unreadable, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY));
        self::assertNull($this->settings->secret(SettingKeys::AI_OPENROUTER_KEY));
    }

    /** .env.local уже з новим ключем (застосунок), старий — через змінну: напрямок навпаки. */
    public function testOldKeyEnvRotatesForeignEnvelopeToCurrentKey(): void
    {
        $oldKey = SecretBox::generateKey();
        $this->settings->set(SettingKeys::AI_OPENROUTER_KEY, (new SecretBox($oldKey))->encrypt(self::SECRET));
        self::assertSame(SecretState::Unreadable, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY));
        $this->env('SETTINGS_SECRET_KEY_OLD', $oldKey);

        $tester = $this->rotate(['--old-key-env' => 'SETTINGS_SECRET_KEY_OLD']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Перешифровано 1', $tester->getDisplay());
        self::assertStringNotContainsString('.env.local', $tester->getDisplay(), 'ключ у .env.local уже новий — підказки про нього нема');
        self::assertSame(SecretState::Ready, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY), 'кеш Settings скинуто командою');
        self::assertSame(self::SECRET, $this->settings->secret(SettingKeys::AI_OPENROUTER_KEY));
    }

    public function testUnreadableEnvelopeIsKeptOrClearedAndSameKeyIsRefused(): void
    {
        $foreign = (new SecretBox(SecretBox::generateKey()))->encrypt(self::SECRET);
        $this->settings->set(SettingKeys::AI_OPENROUTER_KEY, $foreign);
        $this->env(SecretsRotateCommand::NEW_KEY_ENV, SecretBox::generateKey());

        $tester = $this->rotate();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('не розшифрувати 1', $tester->getDisplay());
        self::assertStringContainsString('ввести знову', $tester->getDisplay());
        self::assertSame($foreign, $this->stored(), 'без --clear-unreadable конверт лишається');

        $tester = $this->rotate(['--clear-unreadable' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('', $this->stored());
        self::assertSame(SecretState::Missing, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY));

        $current = $_SERVER['SETTINGS_SECRET_KEY'] ?? getenv('SETTINGS_SECRET_KEY');
        self::assertIsString($current, 'phpunit.dist.xml задає тестовий SETTINGS_SECRET_KEY');
        $this->env(SecretsRotateCommand::NEW_KEY_ENV, $current);
        $tester = $this->rotate();
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('однакові', $tester->getDisplay());
    }

    public function testMissingOrInvalidNewKeyFails(): void
    {
        $this->env(SecretsRotateCommand::NEW_KEY_ENV, '');
        $tester = $this->rotate();
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Новий ключ порожній або некоректний', $tester->getDisplay());

        $this->env('MY_NEW', base64_encode('too-short'));
        self::assertSame(1, $this->rotate(['--new-key-env' => 'MY_NEW'])->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Settings;

use App\Core\Settings\SecretState;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** E15 BYOK: секретний ключ у БД — лише конверт; get() — маска; відкритий текст — лише secret(). */
final class SettingsSecretTest extends KernelTestCase
{
    private Settings $settings;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(Settings::class);
        $this->db = self::getContainer()->get(Connection::class);
    }

    public function testSecretIsStoredEncryptedAndReadMasked(): void
    {
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, '  sk-or-v1-abcdefghijklmnop9999  ');
        $stored = $this->db->fetchOne('SELECT value FROM setting WHERE code = ?', [SettingKeys::AI_OPENROUTER_KEY]);
        self::assertIsString($stored);
        self::assertTrue(SecretBox::isEnvelope($stored));
        self::assertStringNotContainsString('sk-or-v1', $stored);
        self::assertSame('…9999', $this->settings->get(SettingKeys::AI_OPENROUTER_KEY));
        self::assertSame('sk-or-v1-abcdefghijklmnop9999', $this->settings->secret(SettingKeys::AI_OPENROUTER_KEY));
        self::assertNotContains('sk-or-v1-abcdefghijklmnop9999', $this->settings->all(), 'all() віддає сирі значення з БД — там конверт');

        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, null);
        self::assertNull($this->settings->get(SettingKeys::AI_OPENROUTER_KEY));
        self::assertNull($this->settings->secret(SettingKeys::AI_OPENROUTER_KEY));
    }

    /** Той самий Settings, але з іншим SecretBox — як застосунок після зміни чи втрати SETTINGS_SECRET_KEY. */
    private function settingsWith(SecretBox $box, TestHandler $records): Settings
    {
        $logger = new Logger('security_audit', [$records]);
        /** @var CacheInterface $cache */
        $cache = self::getContainer()->get('cache.app');
        /** @var array<string, string|null> $defaults */
        $defaults = self::getContainer()->getParameter('app.settings_defaults');

        return new Settings(self::getContainer()->get(EntityManagerInterface::class), $cache, $box, $logger, $defaults);
    }

    /** M1 рев'ю #70: інший/втрачений ключ шифрування — не 500, а «секрет відсутній» зі станом Unreadable і записом в аудит. */
    public function testSecretStateAndUnreadableEnvelopeIsReportedOnce(): void
    {
        $records = new TestHandler();
        self::assertSame(SecretState::Missing, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY));
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-state-test-1111');
        self::assertSame(SecretState::Ready, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY));

        $other = $this->settingsWith(new SecretBox(SecretBox::generateKey()), $records);
        self::assertSame(SecretState::Unreadable, $other->secretState(SettingKeys::AI_OPENROUTER_KEY));
        self::assertNull($other->secret(SettingKeys::AI_OPENROUTER_KEY), 'secret() не кидає — ключ вважається відсутнім');
        self::assertSame('…1111', $other->get(SettingKeys::AI_OPENROUTER_KEY), 'маска читається й без ключа');
        $other->secret(SettingKeys::AI_OPENROUTER_KEY);
        $other->secretState(SettingKeys::AI_OPENROUTER_KEY);
        $unreadable = array_values(array_filter($records->getRecords(), static fn ($r): bool => 'settings.secret_unreadable' === $r->message));
        self::assertCount(1, $unreadable, 'один запис на процес, не на кожен виклик');
        self::assertSame(SettingKeys::AI_OPENROUTER_KEY, $unreadable[0]->context['code']);
        self::assertSame('…1111', $unreadable[0]->context['mask']);
        self::assertStringNotContainsString('sk-or-v1', json_encode($unreadable[0]->context, \JSON_THROW_ON_ERROR));

        $noKey = $this->settingsWith(new SecretBox(''), new TestHandler());
        self::assertSame(SecretState::NoKey, $noKey->secretState(SettingKeys::AI_OPENROUTER_KEY));
        self::assertNull($noKey->secret(SettingKeys::AI_OPENROUTER_KEY));

        // після повторного введення (новим ключем) — Ready, кеш станів скинуто
        $other->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-re-entered-2222');
        self::assertSame(SecretState::Ready, $other->secretState(SettingKeys::AI_OPENROUTER_KEY));
        self::assertSame('sk-or-v1-re-entered-2222', $other->secret(SettingKeys::AI_OPENROUTER_KEY));
        $this->settings->invalidate();
        self::assertSame(SecretState::Unreadable, $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY), 'для чинного ключа конверт нового тепер нечитний');
    }

    public function testPlainSecretCannotBeStoredThroughSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings->set(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-plain');
    }

    public function testSecretAccessorRejectsNonSecretKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings->secret(SettingKeys::AI_MODEL);
    }

    public function testSecretCodesRegistry(): void
    {
        self::assertSame([SettingKeys::AI_OPENROUTER_KEY, SettingKeys::GOOGLE_CLIENT_SECRET], SettingKeys::secretCodes());
        self::assertTrue(SettingKeys::isValidValue(SettingKeys::GOOGLE_CLIENT_ID, '123456789012-abcdefghijklmnop.apps.googleusercontent.com'));
        self::assertFalse(SettingKeys::isValidValue(SettingKeys::GOOGLE_CLIENT_ID, 'GOCSPX-secret-pasted-by-mistake'));
        self::assertTrue(SettingKeys::isValidValue(SettingKeys::AI_MODEL, 'deepseek/deepseek-v4-pro'));
        self::assertTrue(SettingKeys::isValidValue(SettingKeys::AI_MODEL, 'anthropic/claude-sonnet-4.5:beta'));
        self::assertFalse(SettingKeys::isValidValue(SettingKeys::AI_MODEL, 'no-slash'));
        self::assertTrue(SettingKeys::isValidValue(SettingKeys::AI_MONTHLY_BUDGET_USD, '12.50'));
        self::assertFalse(SettingKeys::isValidValue(SettingKeys::AI_MONTHLY_BUDGET_USD, '-1'));
    }
}

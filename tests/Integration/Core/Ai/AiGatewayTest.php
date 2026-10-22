<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Ai;

use App\Core\Ai\AiChatClient;
use App\Core\Ai\AiFeature;
use App\Core\Ai\AiGateway;
use App\Core\Ai\AiUsageStats;
use App\Core\Ai\Dto\ChatRequest;
use App\Core\Ai\Dto\ChatResponse;
use App\Core\Ai\Dto\KeyInfo;
use App\Core\Ai\Exception\AiAuthException;
use App\Core\Ai\Exception\AiBudgetExceededException;
use App\Core\Ai\Exception\AiException;
use App\Core\Ai\Exception\AiNotConfiguredException;
use App\Core\Ai\Message\PruneAiUsage;
use App\Core\Ai\MessageHandler\PruneAiUsageHandler;
use App\Core\Settings\SecretState;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Schedule;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Contracts\Cache\CacheInterface;

/** E15 BYOK: шлюз — ключ, бюджет, стелі, журнал ai_usage; prune і розклад. */
final class AiGatewayTest extends KernelTestCase
{
    private Settings $settings;
    private Connection $db;
    private int $calls = 0;
    private ?ChatRequest $lastRequest = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(Settings::class);
        $this->db = self::getContainer()->get(Connection::class);
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-gateway-1234');
    }

    /** Settings поверх іншого SecretBox — застосунок після зміни/втрати SETTINGS_SECRET_KEY. */
    private function settingsWith(SecretBox $box): Settings
    {
        /** @var CacheInterface $cache */
        $cache = self::getContainer()->get('cache.app');
        /** @var array<string, string|null> $defaults */
        $defaults = self::getContainer()->getParameter('app.settings_defaults');

        return new Settings(self::getContainer()->get(EntityManagerInterface::class), $cache, $box, new NullLogger(), $defaults);
    }

    private function gateway(?callable $chat = null, ?SecretBox $box = null, int $maxInput = 20000): AiGateway
    {
        $self = $this;
        $client = new class($chat, $self) implements AiChatClient {
            public function __construct(private readonly mixed $chat, private readonly AiGatewayTest $test)
            {
            }

            public function chat(ChatRequest $request): ChatResponse
            {
                $this->test->record($request);
                $chat = $this->chat ?? static fn (ChatRequest $r): ChatResponse => new ChatResponse('ok', $r->model, 10, 5, 0.0021);

                return $chat($request);
            }

            public function keyInfo(): KeyInfo
            {
                $this->test->record(null);

                return new KeyInfo('k', null, 1.0, null, false);
            }
        };

        return new AiGateway($client, null === $box ? $this->settings : $this->settingsWith($box), self::getContainer()->get(AiUsageStats::class), self::getContainer()->get(EntityManagerInterface::class), 'deepseek/deepseek-v4-pro', $maxInput, 777);
    }

    public function record(?ChatRequest $request): void
    {
        ++$this->calls;
        $this->lastRequest = $request;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ai_usage ORDER BY id');
    }

    public function testCompleteWritesUsageRowWithCostAndUsesConfiguredModel(): void
    {
        $this->settings->set(SettingKeys::AI_MODEL, 'z-ai/glm-5.3-flash');
        $gateway = $this->gateway();
        self::assertTrue($gateway->isConfigured());
        $response = $gateway->complete(AiFeature::Describe, null, 'sys', 'user text', null, 'product', 42);
        self::assertSame('ok', $response->content);
        $request = $this->lastRequest;
        self::assertInstanceOf(ChatRequest::class, $request);
        self::assertSame('z-ai/glm-5.3-flash', $request->model);
        self::assertSame(777, $request->maxOutputTokens);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('describe', $rows[0]['feature']);
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame('z-ai/glm-5.3-flash', $rows[0]['model']);
        self::assertSame('product', $rows[0]['entity_type']);
        self::assertSame(42, (int) $rows[0]['entity_id']);
        self::assertSame(10, (int) $rows[0]['prompt_tokens']);
        self::assertSame('0.002100', $rows[0]['cost']);
        self::assertNull($rows[0]['error']);
    }

    public function testInvalidModelFallsBackToDefault(): void
    {
        $this->settings->set(SettingKeys::AI_MODEL, 'not a model');
        self::assertSame('deepseek/deepseek-v4-pro', $this->gateway()->model());
    }

    public function testBudgetZeroMeansNoLimitAndExceededBudgetRejectsWithoutCall(): void
    {
        $this->settings->set(SettingKeys::AI_MONTHLY_BUDGET_USD, '0');
        $gateway = $this->gateway();
        $gateway->complete(AiFeature::Seo, null, 's', 'u');
        self::assertFalse($gateway->budgetExceeded(), '0 = без ліміту');

        $this->settings->set(SettingKeys::AI_MONTHLY_BUDGET_USD, '0,002');
        self::assertTrue($gateway->budgetExceeded(), 'витрачено 0.0021 ≥ 0.002');
        $calls = $this->calls;
        try {
            $gateway->complete(AiFeature::Seo, null, 's', 'u');
            self::fail('очікувався AiBudgetExceededException');
        } catch (AiBudgetExceededException) {
        }
        self::assertSame($calls, $this->calls, 'до провайдера не ходили');
        $rows = $this->rows();
        self::assertSame('rejected', end($rows)['status']);
        self::assertStringContainsString('ліміт', (string) end($rows)['error']);
    }

    public function testNotConfiguredIsRejectedAndNotCalled(): void
    {
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, null);
        $gateway = $this->gateway();
        self::assertFalse($gateway->isConfigured());
        try {
            $gateway->complete(AiFeature::Translate, null, 's', 'u');
            self::fail('очікувався AiNotConfiguredException');
        } catch (AiNotConfiguredException) {
        }
        self::assertSame(0, $this->calls);
        self::assertSame('rejected', $this->rows()[0]['status']);

        self::assertFalse($this->gateway(box: new SecretBox(''))->isConfigured(), 'без ключа шифрування — не налаштовано, навіть якщо конверт у БД є');
    }

    /** M1 рев'ю #70: інший SETTINGS_SECRET_KEY — не 500, а AiNotConfiguredException з поясненням; рядок журналу rejected, не queued. */
    public function testUnreadableKeyIsRejectedWithExplanationAndNotCalled(): void
    {
        $gateway = $this->gateway(box: new SecretBox(SecretBox::generateKey()));
        self::assertSame(SecretState::Unreadable, $gateway->keyState());
        self::assertFalse($gateway->isConfigured());
        self::assertStringContainsString('введіть ключ OpenRouter знову', (string) $gateway->notConfiguredReason());

        foreach ([static fn (AiGateway $g) => $g->keyInfo(), static fn (AiGateway $g) => $g->complete(AiFeature::Describe, null, 's', 'u')] as $call) {
            try {
                $call($gateway);
                self::fail('очікувався AiNotConfiguredException');
            } catch (AiNotConfiguredException $e) {
                self::assertStringContainsString('SETTINGS_SECRET_KEY', $e->getMessage());
            }
        }
        self::assertSame(0, $this->calls, 'до провайдера не ходили');
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame(['rejected', 'rejected'], array_column($rows, 'status'));
        self::assertStringContainsString('введіть ключ OpenRouter знову', (string) $rows[0]['error']);
    }

    /** Будь-який виняток, не лише AiException, закриває рядок журналу: без «завислих» queued. */
    public function testUnexpectedThrowableClosesUsageRowAsError(): void
    {
        $gateway = $this->gateway(static fn (): ChatResponse => throw new RuntimeException('bug with secret sk-or-v1-gateway-1234'));
        try {
            $gateway->complete(AiFeature::Describe, null, 's', 'u');
            self::fail('очікувався RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('bug', $e->getMessage());
        }
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('error', $rows[0]['status']);
        self::assertSame(RuntimeException::class, $rows[0]['error'], 'у журналі — клас, не текст (там може бути що завгодно)');
        self::assertSame(0, $this->db->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'queued'"));
    }

    public function testInputCapAndProviderErrorsAreJournaled(): void
    {
        $gateway = $this->gateway(maxInput: 10);
        try {
            $gateway->complete(AiFeature::Describe, null, 'sys', 'довгий текст понад стелю');
            self::fail('очікувався AiException (стеля)');
        } catch (AiException $e) {
            self::assertStringContainsString('стелю', $e->getMessage());
        }
        self::assertSame(0, $this->calls);

        $gateway = $this->gateway(static fn (): ChatResponse => throw new AiAuthException('OpenRouter 401', 401));
        try {
            $gateway->complete(AiFeature::Describe, null, 'sys', 'u');
            self::fail('очікувався AiAuthException');
        } catch (AiAuthException) {
        }
        $rows = $this->rows();
        self::assertSame(['error', 'rejected'], [$rows[0]['status'], $rows[1]['status']]);
        self::assertSame('OpenRouter 401', $rows[1]['error']);
    }

    public function testKeyInfoIsJournaledAsCheck(): void
    {
        $this->gateway()->keyInfo();
        $rows = $this->rows();
        self::assertSame('check', $rows[0]['feature']);
        self::assertSame('ok', $rows[0]['status']);
    }

    public function testPruneRespectsRetentionAndZeroDisables(): void
    {
        $this->gateway()->complete(AiFeature::Describe, null, 's', 'u');
        $this->db->executeStatement('UPDATE ai_usage SET occurred_at = NOW() - INTERVAL 30 MONTH');
        $stats = self::getContainer()->get(AiUsageStats::class);
        (new PruneAiUsageHandler($stats, new NullLogger(), 0))(new PruneAiUsage());
        self::assertCount(1, $this->rows(), '0 = не прибирати');
        (new PruneAiUsageHandler($stats, new NullLogger(), 24))(new PruneAiUsage());
        self::assertCount(0, $this->rows());
    }

    public function testPruneIsScheduledNightly(): void
    {
        $found = [];
        foreach (self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages() as $m) {
            foreach ($m->getMessages(new MessageContext('default', $m->getId(), $m->getTrigger(), new DateTimeImmutable())) as $message) {
                if ($message instanceof PruneAiUsage) {
                    $found[] = (string) $m->getTrigger();
                }
            }
        }
        self::assertSame(['35 3 * * *'], $found);
    }
}

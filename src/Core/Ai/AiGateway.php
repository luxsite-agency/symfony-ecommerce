<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Ai\Dto\ChatRequest;
use App\Core\Ai\Dto\ChatResponse;
use App\Core\Ai\Dto\KeyInfo;
use App\Core\Ai\Entity\AiUsage;
use App\Core\Ai\Exception\AiAuthException;
use App\Core\Ai\Exception\AiBudgetExceededException;
use App\Core\Ai\Exception\AiException;
use App\Core\Ai\Exception\AiNotConfiguredException;
use App\Core\Ai\Exception\AiPaymentRequiredException;
use App\Core\Security\Entity\User;
use App\Core\Settings\SecretState;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Єдина точка виклику моделі (E15 BYOK): перевіряє, що ключ є, місячний бюджет не вичерпано, вхід не довший за стелю;
 * викликає клієнт; пише рядок ai_usage (ок/помилка, токени, cost). Кнопки в адмінці показуються лише за isConfigured().
 * Ключ, якого не розшифрувати (SETTINGS_SECRET_KEY змінено/втрачено), — не налаштовано з поясненням, не 500; рядок журналу
 * ніколи не лишається queued: будь-який виняток закриває його як rejected/error.
 * Ретраїв тут нема — це робить Messenger у PR-7 за AiException::isRetryable().
 */
final class AiGateway
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly Settings $settings,
        private readonly AiUsageStats $stats,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.ai_default_model%')] private readonly string $defaultModel = 'deepseek/deepseek-v4-pro',
        #[Autowire('%app.ai_max_input_chars%')] private readonly int $maxInputChars = 20000,
        #[Autowire('%app.ai_max_output_tokens%')] private readonly int $maxOutputTokens = 4000,
    ) {
    }

    /** Ключ шифрування є, ключ OpenRouter задано і розшифровується: без цього AI-кнопок в адмінці нема. */
    public function isConfigured(): bool
    {
        return $this->keyState()->isReady();
    }

    /** Стан ключа OpenRouter (Missing / NoKey / Unreadable / Ready) — для банера й бейджа в налаштуваннях. */
    public function keyState(): SecretState
    {
        return $this->settings->secretState(SettingKeys::AI_OPENROUTER_KEY);
    }

    /** Чому AI не налаштовано — текст для flash, журналу й винятку; null, коли все готово. */
    public function notConfiguredReason(): ?string
    {
        return match ($this->keyState()) {
            SecretState::Ready => null,
            SecretState::Missing => 'Ключ OpenRouter не задано',
            SecretState::NoKey => 'SETTINGS_SECRET_KEY не задано на сервері — секретні налаштування й AI вимкнені',
            SecretState::Unreadable => 'Ключ OpenRouter не розшифрувати: SETTINGS_SECRET_KEY на сервері змінено або втрачено — введіть ключ OpenRouter знову',
        };
    }

    /**
     * Перевірка ключа без витрати токенів (кнопка «Перевірити з'єднання»); у журнал пише рядок feature=check.
     *
     * @throws AiException
     */
    public function keyInfo(?User $user = null): KeyInfo
    {
        $usage = new AiUsage(AiFeature::Check, $user?->getId(), $user?->getUserIdentifier());
        $started = hrtime(true);
        try {
            $this->assertConfigured();
            $info = $this->client->keyInfo();
            $usage->ok($this->model(), 0, 0, null, (int) ((hrtime(true) - $started) / 1_000_000));

            return $info;
        } catch (AiException $e) {
            $usage->failed($e instanceof AiAuthException || $e instanceof AiNotConfiguredException ? AiUsageStatus::Rejected : AiUsageStatus::Error, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $usage->failed(AiUsageStatus::Error, $e::class);
            throw $e;
        } finally {
            $this->em->persist($usage);
            $this->em->flush();
        }
    }

    /** @throws AiNotConfiguredException */
    private function assertConfigured(): void
    {
        $reason = $this->notConfiguredReason();
        if (null !== $reason) {
            throw new AiNotConfiguredException($reason);
        }
    }

    public function model(): string
    {
        $model = trim((string) $this->settings->get(SettingKeys::AI_MODEL));

        return '' !== $model && SettingKeys::isValidValue(SettingKeys::AI_MODEL, $model) ? $model : $this->defaultModel;
    }

    /** Ліміт у кредитах; 0 — без ліміту. */
    public function monthlyBudget(): float
    {
        $raw = str_replace(',', '.', trim((string) $this->settings->get(SettingKeys::AI_MONTHLY_BUDGET_USD)));

        return is_numeric($raw) ? max(0.0, (float) $raw) : 0.0;
    }

    public function budgetExceeded(): bool
    {
        $budget = $this->monthlyBudget();

        return $budget > 0 && $this->stats->monthCost() >= $budget;
    }

    /**
     * Виклик із журналом. $system/$user — готові тексти промпта (без ПД покупців за побудовою: обробники PR-7 мають
     * доступ лише до каталогу/контенту).
     *
     * @param array<string, mixed>|null $jsonSchema
     *
     * @throws AiException
     */
    public function complete(AiFeature $feature, ?User $user, string $system, string $userText, ?array $jsonSchema = null, ?string $entityType = null, ?int $entityId = null): ChatResponse
    {
        $usage = new AiUsage($feature, $user?->getId(), $user?->getUserIdentifier(), $entityType, $entityId);
        try {
            $this->assertConfigured();
            if ($this->budgetExceeded()) {
                throw new AiBudgetExceededException(\sprintf('Місячний ліміт витрат AI $%s вичерпано', number_format($this->monthlyBudget(), 2, '.', '')));
            }
            if ($this->maxInputChars > 0 && mb_strlen($system) + mb_strlen($userText) > $this->maxInputChars) {
                throw new AiException(\sprintf('Запит довший за стелю %d символів', $this->maxInputChars));
            }
            $request = new ChatRequest($this->model(), $system, $userText, $this->maxOutputTokens, $jsonSchema);
            $started = hrtime(true);
            $response = $this->client->chat($request);
            $usage->ok($response->model, $response->promptTokens, $response->completionTokens, $response->cost, (int) ((hrtime(true) - $started) / 1_000_000));

            return $response;
        } catch (AiException $e) {
            $rejected = $e instanceof AiNotConfiguredException || $e instanceof AiBudgetExceededException || $e instanceof AiAuthException || $e instanceof AiPaymentRequiredException;
            $usage->failed($rejected ? AiUsageStatus::Rejected : AiUsageStatus::Error, $e->getMessage(), $this->model());
            throw $e;
        } catch (Throwable $e) {
            // не AiException (помилка коду, БД, несподіваний стан): рядок не лишається queued, у журналі — клас без тексту
            $usage->failed(AiUsageStatus::Error, $e::class, $this->model());
            throw $e;
        } finally {
            $this->em->persist($usage);
            $this->em->flush();
        }
    }
}

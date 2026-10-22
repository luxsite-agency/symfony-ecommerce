<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ключі OAuth-клієнта Google для входу покупців (рішення власника 13.10.2026). Пріоритет — «Налаштування магазину»
 * (ROLE_ADMIN): перемикач «Увімкнено» + Client ID правильного формату + Client Secret, що розшифровується (SecretBox,
 * SETTINGS_SECRET_KEY). Поки перемикач вимкнений, працює fallback — env GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET
 * з .env.local (стенди агенції; у магазину клієнта їх нема). Перемикач увімкнено, а ключі неповні — вхід вимкнений
 * (problem() каже чому), а не тихий відкат на env. Без ключів: кнопки «Продовжити з Google» нема ніде, /account/google/* — 404.
 * Нічого не кешується поза Settings (той скидає кеш при збереженні), тож зміна в адмінці діє одразу.
 */
final class GoogleSignInConfig
{
    public function __construct(
        private readonly Settings $settings,
        #[Autowire(env: 'GOOGLE_OAUTH_CLIENT_ID')] private readonly string $envClientId,
        #[Autowire(env: 'GOOGLE_OAUTH_CLIENT_SECRET')] #[SensitiveParameter] private readonly string $envClientSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return GoogleKeySource::None !== $this->resolve()['source'];
    }

    public function source(): GoogleKeySource
    {
        return $this->resolve()['source'];
    }

    /**
     * Чому вхід вимкнений: disabled (перемикач вимкнено, env нема), client_id_missing, client_id_invalid,
     * secret_missing / secret_no_key / secret_unreadable (SecretState). null — увімкнено.
     */
    public function problem(): ?string
    {
        return $this->resolve()['problem'];
    }

    public function clientId(): string
    {
        return $this->resolve()['id'];
    }

    public function clientSecret(): string
    {
        return $this->resolve()['secret'];
    }

    /** @return array{source: GoogleKeySource, id: string, secret: string, problem: string|null} */
    private function resolve(): array
    {
        if ($this->settings->getBool(SettingKeys::GOOGLE_SIGN_IN_ENABLED)) {
            $raw = trim((string) $this->settings->get(SettingKeys::GOOGLE_CLIENT_ID));
            $id = SettingKeys::validValue(SettingKeys::GOOGLE_CLIENT_ID, $raw);
            if (null === $id) {
                return self::off('' === $raw ? 'client_id_missing' : 'client_id_invalid');
            }
            $state = $this->settings->secretState(SettingKeys::GOOGLE_CLIENT_SECRET);
            $secret = $state->isReady() ? $this->settings->secret(SettingKeys::GOOGLE_CLIENT_SECRET) : null;
            if (null === $secret) {
                return self::off('secret_'.$state->value);
            }

            return ['source' => GoogleKeySource::Settings, 'id' => $id, 'secret' => $secret, 'problem' => null];
        }
        $id = trim($this->envClientId);
        $secret = trim($this->envClientSecret);
        if ('' !== $id && '' !== $secret) {
            return ['source' => GoogleKeySource::Env, 'id' => $id, 'secret' => $secret, 'problem' => null];
        }

        return self::off('disabled');
    }

    /** @return array{source: GoogleKeySource, id: string, secret: string, problem: string|null} */
    private static function off(string $problem): array
    {
        return ['source' => GoogleKeySource::None, 'id' => '', 'secret' => '', 'problem' => $problem];
    }
}

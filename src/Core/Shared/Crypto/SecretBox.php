<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Crypto;

use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Шифрування секретів у БД (E15 BYOK): sodium secretbox (XSalsa20-Poly1305), ключ — env SETTINGS_SECRET_KEY
 * (32 байти base64, лише .env.local 600 на сервері; не APP_SECRET і не похідна від нього, щоб витік одного не відкривав
 * друге). Конверт у БД: `enc:v1:<4 останні символи>:<base64(nonce‖ciphertext)>` — маска читається без ключа,
 * версія — для майбутньої зміни формату. Зміна ключа: `app:secrets:rotate` перешифровує всі секрети старим → новим;
 * конверт під іншим ключем — SecretUnreadableException, яку Settings перетворює на «секрет треба ввести знову».
 * Розшифровані значення запам'ятовуються, щоб SecretRedactProcessor ховав їх у логах.
 */
final class SecretBox
{
    public const string PREFIX = 'enc:v1:';

    private ?string $key = null;
    /** @var array<string, true> */
    private array $revealed = [];

    public function __construct(#[Autowire('%env(SETTINGS_SECRET_KEY)%')] #[SensitiveParameter] private readonly string $keyBase64 = '')
    {
    }

    /** Ключ є і він правильної довжини; без нього секретні поля в адмінці неактивні. */
    public function isAvailable(): bool
    {
        return null !== $this->key();
    }

    /**
     * Чому ключа нема: 'empty' — SETTINGS_SECRET_KEY порожній, 'invalid' — не base64 чи не 32 байти (ключ з переносом рядка,
     * обрізаний); null — ключ придатний. Для повідомлень в адмінці й doctor, щоб не шукати годину «чому не задано».
     */
    public function keyProblem(): ?string
    {
        if ('' === trim($this->keyBase64)) {
            return 'empty';
        }

        return null === $this->key() ? 'invalid' : null;
    }

    public function encrypt(#[SensitiveParameter] string $plain): string
    {
        $key = $this->key() ?? throw new RuntimeException('SETTINGS_SECRET_KEY не задано або не 32 байти base64 (bin/console app:secrets:keygen)');
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        $this->revealed[$plain] = true;

        return self::PREFIX.self::last4($plain).':'.base64_encode($nonce.$cipher);
    }

    /** @throws SecretUnreadableException не конверт, нема ключа, інший ключ або пошкоджені дані */
    public function decrypt(string $envelope): string
    {
        if (!self::isEnvelope($envelope)) {
            throw new SecretUnreadableException('Значення не є конвертом SecretBox');
        }
        $key = $this->key() ?? throw new SecretUnreadableException('SETTINGS_SECRET_KEY не задано: секрет не розшифрувати');
        $raw = base64_decode(substr($envelope, strrpos($envelope, ':') + 1), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SecretUnreadableException('Пошкоджений конверт секрету');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        if (false === $plain) {
            throw new SecretUnreadableException('Секрет не розшифровано: інший SETTINGS_SECRET_KEY або пошкоджені дані');
        }
        $this->revealed[$plain] = true;

        return $plain;
    }

    public static function isEnvelope(?string $value): bool
    {
        return null !== $value && 1 === preg_match('/^enc:v1:[^:]{0,4}:[A-Za-z0-9+\/]+=*$/', $value);
    }

    /** Маска «…a1b2» з конверта без розшифрування; null — не задано. */
    public static function mask(?string $envelope): ?string
    {
        if (!self::isEnvelope($envelope)) {
            return null;
        }
        $last4 = explode(':', (string) $envelope, 4)[2];

        return '…'.$last4;
    }

    /**
     * Відкриті значення, які цей процес шифрував або розшифровував, — для маскування в логах.
     *
     * @return list<string>
     */
    public function revealed(): array
    {
        return array_keys($this->revealed);
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    private function key(): ?string
    {
        if (null !== $this->key) {
            return $this->key;
        }
        if ('' === trim($this->keyBase64)) {
            return null;
        }
        $key = base64_decode(trim($this->keyBase64), true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            return null;
        }

        return $this->key = $key;
    }

    /** Маска — лише [A-Za-z0-9]: інший символ (зокрема «:») зламав би конверт; короткий секрет — без маски. */
    private static function last4(string $plain): string
    {
        $tail = \strlen($plain) >= 8 ? substr($plain, -4) : '';

        return 1 === preg_match('/^[A-Za-z0-9]{4}$/', $tail) ? $tail : '';
    }
}

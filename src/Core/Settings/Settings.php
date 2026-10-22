<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Audit\AdminAuditContext;
use App\Core\Settings\Entity\Setting;
use App\Core\Shared\Crypto\SecretBox;
use App\Core\Shared\Crypto\SecretUnreadableException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Читання налаштувань з кешем (cache.app, одна запис на всі ключі), інвалідація при збереженні в адмінці.
 * Значення за замовчуванням — з параметрів контейнера (app.settings_defaults), тож для магазину клієнта
 * їх перевизначає config/store без правки Core. Секретні ключі (SettingKeys::TYPE_SECRET): у БД конверт SecretBox,
 * get() віддає лише маску — відкритий текст тільки через secret(), і лише тому, хто його справді шле провайдеру.
 * Конверт, який не відкривається чинним SETTINGS_SECRET_KEY (ключ змінено/втрачено), — не 500: secretState() = Unreadable,
 * secret() = null, один запис settings.secret_unreadable у security_audit на процес.
 */
#[WithMonologChannel('security_audit')]
final class Settings
{
    private const string CACHE_KEY = 'settings.all';

    /** @var array<string, string|null>|null */
    private ?array $loaded = null;
    /**
     * Розшифровані секрети за кодом (конверт → стан, відкритий текст); скидається разом із кешем.
     *
     * @var array<string, array{raw: string|null, state: SecretState, plain: string|null}>
     */
    private array $secrets = [];
    /** @var array<string, true> коди, про які вже записано в аудит у цьому процесі */
    private array $unreadableReported = [];

    /** @param array<string, string|null> $defaults */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly SecretBox $box,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.settings_defaults%')] private readonly array $defaults = [],
        private readonly ?AdminAuditContext $audit = null,
    ) {
    }

    /** Для секретного ключа — маска («…a1b2») або null: значення звідси не витече в шаблон, API чи лог. */
    public function get(string $code): ?string
    {
        SettingKeys::assertKnown($code);
        if (SettingKeys::isSecret($code)) {
            return SecretBox::mask($this->raw($code));
        }

        return $this->raw($code);
    }

    /**
     * Відкритий секрет; null — не задано, ключа шифрування нема або конверт не розшифрувати (secretState()).
     * Викликати лише там, де значення йде провайдеру.
     */
    public function secret(string $code): ?string
    {
        return $this->resolveSecret($code)['plain'];
    }

    /** Стан секрету для адмінки й шлюзів: лише Ready означає, що secret() віддасть значення. */
    public function secretState(string $code): SecretState
    {
        return $this->resolveSecret($code)['state'];
    }

    /** @return array{raw: string|null, state: SecretState, plain: string|null} */
    private function resolveSecret(string $code): array
    {
        SettingKeys::assertKnown($code);
        if (!SettingKeys::isSecret($code)) {
            throw new InvalidArgumentException(\sprintf('Ключ "%s" не секретний', $code));
        }
        $raw = $this->raw($code);
        if (isset($this->secrets[$code]) && $this->secrets[$code]['raw'] === $raw) {
            return $this->secrets[$code];
        }
        $plain = null;
        if (null === $raw || '' === trim($raw)) {
            $state = SecretState::Missing;
        } elseif (!$this->box->isAvailable()) {
            $state = SecretState::NoKey;
        } else {
            try {
                $plain = $this->box->decrypt($raw);
                $state = SecretState::Ready;
            } catch (SecretUnreadableException $e) {
                $state = SecretState::Unreadable;
                $this->reportUnreadable($code, $raw, $e);
            }
        }

        return $this->secrets[$code] = ['raw' => $raw, 'state' => $state, 'plain' => $plain];
    }

    /** Один запис на код за процес: подія важлива (ключ змінено/втрачено), але не повторювати на кожен запит. */
    private function reportUnreadable(string $code, string $raw, SecretUnreadableException $e): void
    {
        if (isset($this->unreadableReported[$code])) {
            return;
        }
        $this->unreadableReported[$code] = true;
        $this->logger->warning('settings.secret_unreadable', [
            'event' => 'settings.secret_unreadable',
            'code' => $code,
            'mask' => SecretBox::mask($raw),
            'reason' => $e->getMessage(),
            'key_problem' => $this->box->keyProblem(),
        ]);
    }

    public function setSecret(string $code, #[SensitiveParameter] ?string $plain): void
    {
        SettingKeys::assertKnown($code);
        if (!SettingKeys::isSecret($code)) {
            throw new InvalidArgumentException(\sprintf('Ключ "%s" не секретний', $code));
        }
        $plain = null === $plain ? null : trim($plain);
        $this->set($code, null === $plain || '' === $plain ? null : $this->box->encrypt($plain));
    }

    private function raw(string $code): ?string
    {
        $all = $this->all();
        if (\array_key_exists($code, $all) && null !== $all[$code]) {
            return $all[$code];
        }

        return $this->defaults[$code] ?? null;
    }

    public function getBool(string $code): bool
    {
        return \in_array(mb_strtolower(trim((string) $this->get($code))), ['1', 'true', 'yes', 'on', 'так'], true);
    }

    /** @return list<string> */
    public function getList(string $code): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $this->get($code))), static fn (string $v): bool => '' !== $v));
    }

    /**
     * Усі значення з БД (без дефолтів).
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return $this->loaded ??= $this->cache->get(self::CACHE_KEY, function (): array {
            $rows = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
                $rows[$setting->getCode()] = $setting->getValue();
            }

            return $rows;
        });
    }

    /** Секретний ключ сюди — лише конвертом (setSecret) або null, щоб відкритий текст не ліг у БД. */
    public function set(string $code, ?string $value): void
    {
        if (SettingKeys::isSecret($code) && null !== $value && '' !== trim($value) && !SecretBox::isEnvelope($value)) {
            throw new InvalidArgumentException(\sprintf('Секрет "%s" зберігати через setSecret()', $code));
        }
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['code' => $code]) ?? new Setting($code);
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
        $this->invalidate();
    }

    /** Рядки для всіх відомих ключів, щоб список в адмінці був повним (значення — null, тобто дефолт). */
    public function ensureRows(): void
    {
        $existing = array_map(static fn (Setting $s): string => $s->getCode(), $this->em->getRepository(Setting::class)->findAll());
        $missing = array_diff(SettingKeys::all(), $existing);
        if ([] === $missing) {
            return;
        }
        foreach ($missing as $code) {
            $this->em->persist(new Setting($code));
        }
        // порожні рядки — службове, не дія того, хто відкрив сторінку: без «Створено» в журналі змін (аудит UX A13)
        null === $this->audit ? $this->em->flush() : $this->audit->unaudited(fn () => $this->em->flush());
        $this->invalidate();
    }

    public function invalidate(): void
    {
        $this->loaded = null;
        $this->secrets = [];
        $this->cache->delete(self::CACHE_KEY);
    }
}

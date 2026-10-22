<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Crypto\Command;

use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Core\Shared\Crypto\SecretUnreadableException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ротація SETTINGS_SECRET_KEY: усі секретні налаштування (SettingKeys::secretCodes()) перешифровуються старим ключем → новим
 * в одній транзакції. Ключі не передаються аргументами (історія shell, ps) — лише змінними середовища:
 *
 *   1) новий ключ ще не в .env.local (звичайна ротація):
 *      SETTINGS_SECRET_KEY_NEW=$(bin/console app:secrets:keygen | cut -d= -f2) bin/console app:secrets:rotate
 *      → далі записати новий ключ у .env.local, cache:clear; до цього секрети «не розшифрувати»;
 *   2) .env.local уже з новим ключем, старий ще відомий (бекап на новому сервері):
 *      SETTINGS_SECRET_KEY_OLD=… bin/console app:secrets:rotate --old-key-env=SETTINGS_SECRET_KEY_OLD
 *
 * Конверт, що не відкривається старим ключем, пропускається (або стирається з --clear-unreadable): його все одно доведеться
 * ввести знову. Кожна зміна — у security_audit (settings.secret_rotated / secret_cleared, маска, без значення).
 */
#[AsCommand(name: 'app:secrets:rotate', description: 'Перешифрувати секрети налаштувань зі старого SETTINGS_SECRET_KEY на новий')]
#[WithMonologChannel('security_audit')]
final class SecretsRotateCommand extends Command
{
    public const string NEW_KEY_ENV = 'SETTINGS_SECRET_KEY_NEW';

    public function __construct(
        private readonly SecretBox $current,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('new-key-env', null, InputOption::VALUE_REQUIRED, 'Змінна середовища з НОВИМ ключем; старий — чинний SETTINGS_SECRET_KEY', self::NEW_KEY_ENV)
            ->addOption('old-key-env', null, InputOption::VALUE_REQUIRED, 'Змінна середовища зі СТАРИМ ключем; новий — чинний SETTINGS_SECRET_KEY (ключ у .env.local уже замінено)')
            ->addOption('clear-unreadable', null, InputOption::VALUE_NONE, 'Конверти, що не відкриваються старим ключем, стерти (інакше лишити як є)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що буде зроблено');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $clear = (bool) $input->getOption('clear-unreadable');

        /** @var string|null $oldEnv */
        $oldEnv = $input->getOption('old-key-env');
        if (null !== $oldEnv) {
            $old = new SecretBox(self::env($oldEnv));
            $new = $this->current;
            $direction = \sprintf('старий ключ із $%s → чинний SETTINGS_SECRET_KEY', $oldEnv);
        } else {
            /** @var string $newEnv */
            $newEnv = $input->getOption('new-key-env');
            $old = $this->current;
            $new = new SecretBox(self::env($newEnv));
            $direction = \sprintf('чинний SETTINGS_SECRET_KEY → новий ключ із $%s', $newEnv);
        }
        if (!$old->isAvailable()) {
            $io->error('Старий ключ порожній або некоректний (32 байти base64, 44 символи одним рядком).');

            return Command::FAILURE;
        }
        if (!$new->isAvailable()) {
            $io->error('Новий ключ порожній або некоректний: bin/console app:secrets:keygen і передати через змінну середовища.');

            return Command::FAILURE;
        }
        $probe = $old->encrypt('rotate-probe');
        try {
            $new->decrypt($probe);
            $io->error('Старий і новий ключ однакові — перешифровувати нічого.');

            return Command::FAILURE;
        } catch (SecretUnreadableException) {
        }

        $io->text('Ротація: '.$direction.($dryRun ? ' (dry-run)' : ''));
        $repo = $this->em->getRepository(Setting::class);
        $rotated = $unreadable = $empty = 0;
        $this->em->wrapInTransaction(function () use ($repo, $old, $new, $io, $dryRun, $clear, &$rotated, &$unreadable, &$empty): void {
            foreach (SettingKeys::secretCodes() as $code) {
                $setting = $repo->findOneBy(['code' => $code]);
                $raw = $setting?->getValue();
                if (null === $setting || null === $raw || '' === trim($raw)) {
                    ++$empty;
                    $io->text(\sprintf('  %s: порожньо', $code));
                    continue;
                }
                try {
                    $plain = $old->decrypt($raw);
                } catch (SecretUnreadableException $e) {
                    ++$unreadable;
                    $io->warning(\sprintf('%s (%s): не розшифрувати старим ключем — %s%s', $code, SecretBox::mask($raw) ?? '?', $e->getMessage(), $clear ? ($dryRun ? '; буде стерто' : '; стираю') : '; лишаю — ввести знову в адмінці'));
                    if ($clear && !$dryRun) {
                        $setting->setValue(null);
                        $this->audit('cleared', $code, null);
                    }
                    continue;
                }
                ++$rotated;
                $io->text(\sprintf('  %s (%s): перешифровано', $code, SecretBox::mask($raw) ?? ''));
                if (!$dryRun) {
                    $setting->setValue($new->encrypt($plain));
                    $this->audit('rotated', $code, $setting->getValue());
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        });
        if (!$dryRun) {
            $this->settings->invalidate();
        }

        $io->success(\sprintf('Перешифровано %d, не розшифрувати %d, порожніх %d.', $rotated, $unreadable, $empty));
        if (!$dryRun && null === $oldEnv) {
            $io->note('Далі: записати новий ключ у .env.local як SETTINGS_SECRET_KEY (600) і скинути кеш (cache:clear, перезапуск php). Доти секрети в адмінці — «не розшифрувати».');
        }

        return Command::SUCCESS;
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? trim($value) : '';
    }

    private function audit(string $action, string $code, ?string $envelope): void
    {
        $this->logger->info('settings.secret_'.$action, [
            'event' => 'settings.secret_'.$action,
            'code' => $code,
            'mask' => SecretBox::mask($envelope),
            'user_id' => null,
            'source' => 'cli',
        ]);
    }
}

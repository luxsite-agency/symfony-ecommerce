<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Command;

use App\Site\Theme\SiteTheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перевірка теми розгортання (app.theme з config/store/parameters.local.yaml): невідомі ключі й файли тем під public/.
 * Для зібраного образу магазину — перед перемиканням релізу; для паку до збірки — `bin/store validate <пак>`,
 * який читає схему ключів з config/store/theme-schema.json (`store:validate --schema`, синхронність тримає тест).
 */
#[AsCommand(name: 'store:validate', description: 'Перевірити тему магазину (app.theme): ключі й файли')]
final class StoreValidateCommand
{
    /**
     * @param array<mixed> $theme
     */
    public function __construct(
        #[Autowire('%app.theme%')] private readonly array $theme,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Вивести схему ключів теми (JSON) замість перевірки')] bool $schema = false): int
    {
        if ($schema) {
            $io->writeln((string) json_encode(SiteTheme::schema(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $errors = SiteTheme::validate($this->theme);
        // лого обов'язкове (SiteTheme::validate), файл може лежати й поза themes/ (дефолт рушія assets/logo.svg) — перевіряємо окремо
        $logo = $this->theme['logo']['src'] ?? null;
        if (\is_string($logo) && '' !== $logo && !preg_match('#^themes/#', $logo) && !is_file($this->publicDir.'/'.ltrim($logo, '/'))) {
            $errors[] = 'logo.src: нема файлу public/'.ltrim($logo, '/');
        }
        foreach (self::assetPaths($this->theme) as $path => $file) {
            if (!is_file($this->publicDir.'/'.$file)) {
                $errors[] = $path.': нема файлу public/'.$file;
            }
        }
        if ([] !== $errors) {
            $io->error(['app.theme має помилки:', ...$errors]);

            return Command::FAILURE;
        }
        $io->success(\sprintf('Тема «%s»: ключі й файли в порядку', (string) ($this->theme['key'] ?? SiteTheme::DEFAULTS['key'])));

        return Command::SUCCESS;
    }

    /**
     * Шляхи до файлів теми (рядки «themes/…» з розширенням) у будь-якому вузлі.
     *
     * @param array<mixed> $node
     *
     * @return array<string, string> шлях ключа => файл відносно public/
     */
    public static function assetPaths(array $node, string $prefix = ''): array
    {
        $paths = [];
        foreach ($node as $key => $value) {
            $keyPath = ('' === $prefix ? '' : $prefix.'.').$key;
            if (\is_array($value)) {
                $paths += self::assetPaths($value, $keyPath);
            } elseif (\is_string($value) && preg_match('#^themes/[^\s]+\.[A-Za-z0-9]+$#', $value)) {
                $paths[$keyPath] = $value;
            }
        }

        return $paths;
    }
}

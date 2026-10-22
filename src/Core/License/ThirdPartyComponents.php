<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\License;

use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Сторонні компоненти рушія для сторінки ліцензії: runtime-пакети composer.lock (без packages-dev) — назва, версія, ліцензія.
 * composer.lock на кожен запит не парситься: перелік лежить у файлі app.license_components_file, який пише
 * `bin/console license:components` у збірці образу (Dockerfile.prod); нема файлу або він від іншого composer.lock (dev після
 * composer update) — перелік рахується й записується при першому запиті. Збій запису (read-only var/) — попередження в лог, а перелік
 * тримає cache.app (ключ — відбиток lock), щоб не розбирати ~1 МБ JSON на кожен GET /license (рев'ю #67 L2).
 */
final class ThirdPartyComponents
{
    /** @var list<array{name: string, version: string, license: string}>|null */
    private ?array $components = null;

    public function __construct(
        #[Autowire('%app.license_components_file%')] private readonly string $file,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly CacheInterface $cache = new ArrayAdapter(),
    ) {
    }

    /** @return list<array{name: string, version: string, license: string}> за назвою пакета */
    public function all(): array
    {
        if (null !== $this->components) {
            return $this->components;
        }
        $cached = $this->read();
        if (null !== $cached) {
            return $this->components = $cached;
        }
        /** @var list<array{name: string, version: string, license: string}> $components */
        $components = $this->cache->get('license_components.'.($this->lockStamp() ?? 'none'), function (): array {
            $components = $this->fromComposerLock();
            try {
                $this->write($components);
            } catch (IOExceptionInterface $e) {
                $this->logger->warning('license:components — не вдалося записати {file}: {error}', ['file' => $this->file, 'error' => $e->getMessage()]);
            }

            return $components;
        });

        return $this->components = $components;
    }

    /**
     * Перелік з composer.lock (лише `packages`, без `packages-dev`); ліцензія — через « / », якщо пакет пропонує кілька на вибір.
     *
     * @return list<array{name: string, version: string, license: string}>
     */
    public function fromComposerLock(?string $lockFile = null): array
    {
        $lockFile ??= $this->lockFile();
        $lock = self::decode((string) file_get_contents($lockFile));
        $components = [];
        foreach ($lock['packages'] ?? [] as $package) {
            if (!\is_array($package) || !\is_string($package['name'] ?? null)) {
                continue;
            }
            $licenses = array_values(array_filter((array) ($package['license'] ?? []), is_string(...)));
            $components[] = [
                'name' => $package['name'],
                'version' => (string) ($package['version'] ?? ''),
                'license' => [] === $licenses ? 'невідомо' : implode(' / ', $licenses),
            ];
        }
        usort($components, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $components;
    }

    /** @param list<array{name: string, version: string, license: string}> $components */
    public function write(array $components): void
    {
        $this->filesystem->dumpFile($this->file, json_encode([
            'generated_at' => date(\DATE_ATOM),
            'lock' => $this->lockStamp(),
            'components' => $components,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Пакети, усі варіанти ліцензії яких — copyleft (GPL, LGPL, AGPL): у runtime-залежностях рушія під Apache 2.0 їх бути не має (GPL не сумісна з умовами поширення рушія).
     * Пакет «MIT або GPL» — не copyleft (ліцензіат обирає MIT); без ліцензії — теж у списку, бо умови невідомі.
     *
     * @param list<array{name: string, version: string, license: string}> $components
     *
     * @return list<array{name: string, version: string, license: string}>
     */
    public static function copyleft(array $components): array
    {
        return array_values(array_filter($components, static function (array $c): bool {
            if ('невідомо' === $c['license']) {
                return true;
            }
            foreach (explode(' / ', $c['license']) as $option) {
                if (!str_contains(strtoupper($option), 'GPL')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<array{name: string, version: string, license: string}>|null null — файлу нема, він зламаний або від іншого composer.lock */
    private function read(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        try {
            $data = self::decode((string) file_get_contents($this->file));
        } catch (JsonException) {
            return null;
        }
        if (($data['lock'] ?? null) !== $this->lockStamp() || !\is_array($data['components'] ?? null)) {
            return null;
        }
        $components = [];
        foreach ($data['components'] as $c) {
            if (!\is_array($c) || !\is_string($c['name'] ?? null) || !\is_string($c['version'] ?? null) || !\is_string($c['license'] ?? null)) {
                return null;
            }
            $components[] = ['name' => $c['name'], 'version' => $c['version'], 'license' => $c['license']];
        }

        return $components;
    }

    /** Відбиток composer.lock (розмір і mtime — без читання файлу): перелік від іншого lock-файлу не показується. */
    private function lockStamp(): ?string
    {
        $lock = $this->lockFile();

        return is_file($lock) ? filesize($lock).'-'.filemtime($lock) : null;
    }

    private function lockFile(): string
    {
        return $this->projectDir.'/composer.lock';
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }
}

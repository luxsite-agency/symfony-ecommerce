<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Export;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/** Файли експорту в app.customer_export_dir (поза public/): ім'я — sha256 токена; прибирання старших за добу (токен уже прострочений). */
final class ExportFiles
{
    public function __construct(
        #[Autowire('%app.customer_export_dir%')] private readonly string $dir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    public function write(string $name, string $json): void
    {
        $this->fs->mkdir($this->dir, 0750);
        $this->fs->dumpFile($this->path($name), $json);
        $this->fs->chmod($this->path($name), 0640);
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /** Вміст і видалення: посилання одноразове. null — файлу ще нема (черга не дійшла) або вже забрано. */
    public function take(string $name): ?string
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return null;
        }
        $content = (string) file_get_contents($path);
        $this->fs->remove($path);

        return $content;
    }

    public function prune(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 86400 - 3600) {
                $this->fs->remove($file);
                ++$removed;
            }
        }

        return $removed;
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $name)) {
            throw new InvalidArgumentException('Очікується sha256');
        }

        return $this->dir.'/'.$name.'.json';
    }
}

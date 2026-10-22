<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\License;

use DateTimeImmutable;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Версія рушія для сторінки «Ліцензія» в адмінці: з файлу STORE_RELEASE, який bin/store deploy кладе в контекст збірки
 * (engine_sha, engine_ref, pack_hash, built_at…), а в розгортанні без нього (dev, worktree) — sha HEAD з git; інакше null
 * («невідомо»). Читається один раз на запит.
 */
final class EngineRelease
{
    private const string FILE = 'STORE_RELEASE';

    /** @var array<string, string>|null */
    private ?array $data = null;

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** Короткий sha коміту рушія (12 знаків) або null. */
    public function sha(): ?string
    {
        return $this->all()['engine_sha'] ?? null;
    }

    /** Тег або гілка, з якої зібрано реліз (engine_ref), якщо відома. */
    public function ref(): ?string
    {
        $ref = $this->all()['engine_ref'] ?? null;

        return null === $ref || 'HEAD' === $ref || $ref === $this->sha() ? null : $ref;
    }

    public function builtAt(): ?DateTimeImmutable
    {
        $value = $this->all()['built_at'] ?? null;
        if (null === $value) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    public function packHash(): ?string
    {
        return $this->all()['pack_hash'] ?? null;
    }

    /** @return array<string, string> ключ → значення STORE_RELEASE (або лише engine_sha з git) */
    public function all(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }
        $data = [];
        $file = $this->projectDir.'/'.self::FILE;
        if (is_file($file)) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    if ('' !== trim($key) && '' !== trim($value)) {
                        $data[trim($key)] = trim($value);
                    }
                }
            }
        }
        if (!isset($data['engine_sha']) && null !== $sha = $this->gitSha()) {
            $data['engine_sha'] = $sha;
        }

        return $this->data = $data;
    }

    private function gitSha(): ?string
    {
        if (!file_exists($this->projectDir.'/.git')) {
            return null;
        }
        try {
            $process = new Process(['git', '-C', $this->projectDir, 'rev-parse', '--short=12', 'HEAD'], null, null, null, 3);
            $process->run();
            $sha = trim($process->getOutput());

            return $process->isSuccessful() && 1 === preg_match('/^[0-9a-f]{7,40}$/', $sha) ? $sha : null;
        } catch (ProcessException) {
            return null;
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Crypto;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Страховка від секретів у логах: відкриті значення, які SecretBox шифрував/розшифровував у цьому процесі
 * (ключ OpenRouter), у message і context замінюються маскою. HttpClient Symfony заголовків не логує, але dump
 * винятку чи відповіді провайдера — може. БД процесор не чіпає (інакше рекурсія через логер Doctrine).
 */
#[AsMonologProcessor]
final class SecretRedactProcessor
{
    public function __construct(private readonly SecretBox $box)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = $this->box->revealed();
        if ([] === $secrets) {
            return $record;
        }
        $masks = array_map(static fn (string $s): string => '…'.substr($s, -4), $secrets);

        return $record->with(
            message: str_replace($secrets, $masks, $record->message),
            context: $this->redact($record->context, $secrets, $masks),
            extra: $this->redact($record->extra, $secrets, $masks),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $secrets
     * @param list<string>            $masks
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $data, array $secrets, array $masks): array
    {
        foreach ($data as $k => $v) {
            if (\is_string($v)) {
                $data[$k] = str_replace($secrets, $masks, $v);
            } elseif (\is_array($v)) {
                $data[$k] = $this->redact($v, $secrets, $masks);
            }
        }

        return $data;
    }
}

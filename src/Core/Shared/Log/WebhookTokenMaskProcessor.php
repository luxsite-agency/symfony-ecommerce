<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Маскує `token=…` у повідомленні й контексті кожного запису логів (рев'ю #56, п. 3): при 500 у вебхуку CRM
 * fingers_crossed скидає буфер з `request_uri`/`url`, де є `?token=` вебхука. Рекурсивно по всіх рядках контексту.
 */
#[AsMonologProcessor]
final class WebhookTokenMaskProcessor
{
    private const string PATTERN = '/(\btoken=)[^&\s"\']+/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->mask($record->context);
        $extra = $this->mask($record->extra);
        $message = (string) preg_replace(self::PATTERN, '$1***', $record->message);
        if ($context === $record->context && $extra === $record->extra && $message === $record->message) {
            return $record;
        }

        return $record->with(message: $message, context: \is_array($context) ? $context : [], extra: \is_array($extra) ? $extra : []);
    }

    private function mask(mixed $value): mixed
    {
        if (\is_string($value)) {
            return preg_replace(self::PATTERN, '$1***', $value);
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->mask($item);
            }
        }

        return $value;
    }
}

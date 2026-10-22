<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Plan;

use App\Core\Changeset\Validate\IssueMessage;

/**
 * Знахідка dry-run: помилка (пакет не створюється / не застосовується), попередження (інформація менеджеру) або ризик
 * (прапорець, потрібне явне підтвердження при застосуванні — рішення власника 11.10, п. 4). index — номер операції в пакеті,
 * null — про пакет загалом. message — український текст для API/CLI; translatable — той самий текст ключем changeset.issue.*
 * для адмінки (IssueMessage), якщо знахідку створено з ключем.
 */
final readonly class Issue
{
    public const string ERROR = 'error';
    public const string WARNING = 'warning';
    public const string RISK = 'risk';

    /** @param array<string, mixed> $details */
    private function __construct(
        public string $kind,
        public ?int $index,
        public string $code,
        public string $message,
        public array $details = [],
        public ?IssueMessage $translatable = null,
    ) {
    }

    /** @param array<string, mixed> $details */
    private static function make(string $kind, ?int $index, string $code, string|IssueMessage $message, array $details): self
    {
        return new self($kind, $index, $code, (string) $message, $details, $message instanceof IssueMessage ? $message : null);
    }

    /** Текст для адмінки: з ключем, якщо є, інакше український. */
    public function text(): IssueMessage
    {
        return $this->translatable ?? new IssueMessage($this->message);
    }

    /** @param array<string, mixed> $details */
    public static function error(?int $index, string $code, string|IssueMessage $message, array $details = []): self
    {
        return self::make(self::ERROR, $index, $code, $message, $details);
    }

    /** @param array<string, mixed> $details */
    public static function warning(?int $index, string $code, string|IssueMessage $message, array $details = []): self
    {
        return self::make(self::WARNING, $index, $code, $message, $details);
    }

    /** @param array<string, mixed> $details */
    public static function risk(?int $index, string $code, string|IssueMessage $message, array $details = []): self
    {
        return self::make(self::RISK, $index, $code, $message, $details);
    }

    /** @return array{index: int|null, code: string, message: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return ['index' => $this->index, 'code' => $this->code, 'message' => $this->message, 'details' => $this->details];
    }
}

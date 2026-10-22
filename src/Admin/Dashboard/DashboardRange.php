<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Період дашборда: початок і кінець включно, по днях, у часовій зоні PHP (як Order::placedAt). Пресети (?period=) —
 * сьогодні, вчора, 7/30/90 днів, цей і минулий місяць; довільний діапазон — ?from=YYYY-MM-DD&to=YYYY-MM-DD.
 * Розбір строгий: лише формат Y-m-d без хвоста, from ≤ to, to не пізніше сьогодні, не більше MAX_DAYS днів; інакше
 * повідомлення (error — ключ перекладу dashboard.range.error.*) і типові 30 днів. У SQL ідуть лише параметри
 * start()/endExclusive(), жодної інтерполяції.
 */
final readonly class DashboardRange
{
    public const string DEFAULT = '30';
    public const int MAX_DAYS = 731;
    /** @var list<string> */
    public const array PRESETS = ['today', 'yesterday', '7', '30', '90', 'month', 'last_month'];

    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        /** ключ пресету або 'custom' */
        public string $preset,
        /** ключ помилки розбору, коли показано типовий період замість запитаного */
        public ?string $error = null,
    ) {
    }

    public static function preset(string $key, ?DateTimeImmutable $now = null): self
    {
        $today = ($now ?? new DateTimeImmutable())->setTime(0, 0);
        [$from, $to] = match ($key) {
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            '7', '30', '90' => [$today->modify(\sprintf('-%d days', (int) $key - 1)), $today],
            'month' => [$today->modify('first day of this month'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            default => throw new InvalidArgumentException(\sprintf('Невідомий пресет періоду "%s"', $key)),
        };

        return new self($from, $to, $key);
    }

    /** Довільний діапазон; ключ помилки — коли дати не приймаються. */
    public static function custom(string $from, string $to, ?DateTimeImmutable $now = null): self
    {
        $today = ($now ?? new DateTimeImmutable())->setTime(0, 0);
        $start = self::parseDay($from);
        $end = self::parseDay($to);
        if (null === $start || null === $end) {
            return self::fallback($today, 'invalid');
        }
        $error = match (true) {
            $start > $end => 'order',
            $end > $today => 'future',
            (int) $start->diff($end)->days + 1 > self::MAX_DAYS => 'too_long',
            default => null,
        };
        if (null !== $error) {
            return self::fallback($today, $error);
        }
        foreach (self::PRESETS as $key) {
            $candidate = self::preset($key, $today);
            if ($candidate->from == $start && $candidate->to == $end) {
                return $candidate;
            }
        }

        return new self($start, $end, 'custom');
    }

    /** З query-параметрів: from/to мають пріоритет над period; порожні або невідомі → типові 30 днів. */
    public static function fromQuery(?string $period, ?string $from, ?string $to, ?DateTimeImmutable $now = null): self
    {
        $from = trim((string) $from);
        $to = trim((string) $to);
        if ('' !== $from || '' !== $to) {
            return self::custom($from, $to, $now);
        }
        $period = trim((string) $period);
        if ('' === $period) {
            return self::preset(self::DEFAULT, $now);
        }

        return \in_array($period, self::PRESETS, true) ? self::preset($period, $now) : self::fallback(($now ?? new DateTimeImmutable())->setTime(0, 0), 'preset');
    }

    private static function fallback(DateTimeImmutable $today, string $error): self
    {
        $default = self::preset(self::DEFAULT, $today);

        return new self($default->from, $default->to, $default->preset, $error);
    }

    private static function parseDay(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null; // 2026-02-30 парситься з переносом — не приймаємо
        }

        return $day->format('Y-m-d') === $value ? $day : null;
    }

    /** Початок першого дня (параметр для placed_at >= ?). */
    public function start(): DateTimeImmutable
    {
        return $this->from;
    }

    /** Початок дня після останнього (параметр для placed_at < ?): кінець включно без 23:59:59. */
    public function endExclusive(): DateTimeImmutable
    {
        return $this->to->modify('+1 day');
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** Крок графіка: до 92 днів — дні, до 366 — тижні (з понеділка), далі місяці. */
    public function granularity(): string
    {
        $days = $this->days();

        return match (true) {
            $days > 366 => 'month',
            $days > 92 => 'week',
            default => 'day',
        };
    }

    /** Ключ кешу: лише дати, тож той самий діапазон з пресету й з полів — один запис. */
    public function cacheKey(): string
    {
        return $this->from->format('Y-m-d').'_'.$this->to->format('Y-m-d');
    }

    public function isPreset(string $key): bool
    {
        return $this->preset === $key;
    }

    /** @return array<string, string> query-параметри для посилань на цей самий діапазон */
    public function query(): array
    {
        return 'custom' === $this->preset ? ['from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')] : ['period' => $this->preset];
    }
}

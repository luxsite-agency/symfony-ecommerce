<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Money;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use JsonSerializable;
use NumberFormatter;

/**
 * Гроші: ціле число мінорних одиниць (копійки, центи) + ISO-код валюти (ADR D5).
 * Жодних float. Кількість у рядку може бути дробовою (DECIMAL(12,3), Rev 5) — множення через bcmath з округленням half-up.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {
    }

    public static function of(int $minorAmount, string $currency): self
    {
        return new self($minorAmount, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    /** "1234.56" → 123456 (рядок, не float: "1234,5" теж приймається). */
    public static function fromDecimalString(string $decimal, string $currency, int $scale = 2): self
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($decimal));
        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не грошовий рядок: "%s"', $decimal), 'error.money.invalid', ['value' => $decimal]);
        }

        return new self(self::roundHalfUp(bcmul($normalized, bcpow('10', (string) $scale, 0), $scale + 1)), self::normalizeCurrency($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /** Множення на дробову кількість ("2.500") із округленням half-up до мінорної одиниці. */
    public function multiplyByDecimal(string $quantity): self
    {
        $normalized = str_replace(',', '.', trim($quantity));
        if (!preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не кількість: "%s"', $quantity), 'error.quantity.invalid_plain', ['value' => $quantity]);
        }

        return new self(self::roundHalfUp(bcmul((string) $this->amount, $normalized, 6)), $this->currency);
    }

    /** Відсоток у базисних пунктах: 1500 = 15,00 %. Округлення half-up. */
    public function percentage(int $basisPoints): self
    {
        return new self(self::roundHalfUp(bcdiv(bcmul((string) $this->amount, (string) $basisPoints, 0), '10000', 6)), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return 0 === $this->amount;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }

    /** "1234.56" — для збереження/логів/API, без локалі. */
    public function toDecimalString(int $scale = 2): string
    {
        $negative = $this->amount < 0;
        $abs = (string) abs($this->amount);
        if ($scale <= 0) {
            return ($negative ? '-' : '').$abs;
        }
        $abs = str_pad($abs, $scale + 1, '0', \STR_PAD_LEFT);
        $int = substr($abs, 0, -$scale);
        $frac = substr($abs, -$scale);

        return ($negative ? '-' : '').$int.'.'.$frac;
    }

    /** Локалізований формат: "1 234,56 ₴" (uk), "$1,234.56" (en_US). */
    public function format(string $locale = 'uk'): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return $formatter->formatCurrency((float) $this->toDecimalString(), $this->currency) ?: $this->toDecimalString().' '.$this->currency;
    }

    /** @return array{amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException($this->currency, $other->currency);
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не ISO-код валюти: "%s"', $currency), 'error.money.invalid_currency', ['value' => $currency]);
        }

        return $currency;
    }

    /** Округлення half-up рядкового десяткового значення до цілого. */
    private static function roundHalfUp(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        $abs = ltrim($decimal, '-');
        [$int, $frac] = array_pad(explode('.', $abs, 2), 2, '');
        $int = ltrim($int, '0') ?: '0';
        if ('' !== $frac && (int) $frac[0] >= 5) {
            $int = bcadd($int, '1', 0);
        }
        if (bccomp($int, (string) \PHP_INT_MAX, 0) > 0) {
            throw new TranslatableInvalidArgumentException('Сума виходить за межі int', 'error.money.overflow');
        }

        return (int) $int * ($negative ? -1 : 1);
    }
}

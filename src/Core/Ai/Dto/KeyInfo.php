<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Dto;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET /api/v1/key: підпис ключа, його ліміт і витрати в кредитах OpenRouter (null — без ліміту). Токенів не витрачає.
 * summary() — українською (логи, тести клієнта); trans() — те саме мовою адмінки («Перевірити з'єднання», ai_key.summary.*).
 */
final readonly class KeyInfo implements TranslatableInterface
{
    public function __construct(
        public ?string $label,
        public ?float $limit,
        public float $usage,
        public ?float $limitRemaining,
        public bool $freeTier,
    ) {
    }

    public function summary(): string
    {
        $parts = [null !== $this->label && '' !== $this->label ? 'ключ «'.$this->label.'»' : 'ключ без підпису'];
        $parts[] = 'витрачено $'.number_format($this->usage, 2, '.', '');
        $parts[] = null === $this->limit ? 'без ліміту на ключі' : 'ліміт $'.number_format($this->limit, 2, '.', '').(null === $this->limitRemaining ? '' : ', лишилось $'.number_format($this->limitRemaining, 2, '.', ''));
        if ($this->freeTier) {
            $parts[] = 'безкоштовний рівень';
        }

        return implode(', ', $parts);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $t = static fn (string $key, array $params = []): string => $translator->trans('ai_key.summary.'.$key, $params, 'admin', $locale);
        // суми — як у summary(): крапка, 2 знаки (форматування грошей мовою адмінки — окремий PR)
        $money = static fn (float $v): string => number_format($v, 2, '.', '');
        $parts = [null !== $this->label && '' !== $this->label ? $t('label', ['label' => $this->label]) : $t('no_label')];
        $parts[] = $t('spent', ['amount' => $money($this->usage)]);
        $parts[] = match (true) {
            null === $this->limit => $t('no_limit'),
            null === $this->limitRemaining => $t('limit', ['amount' => $money($this->limit)]),
            default => $t('limit_remaining', ['amount' => $money($this->limit), 'remaining' => $money($this->limitRemaining)]),
        };
        if ($this->freeTier) {
            $parts[] = $t('free_tier');
        }

        return implode(', ', $parts);
    }
}

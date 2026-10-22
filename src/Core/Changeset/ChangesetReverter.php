<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use App\Core\Changeset\Dto\RemovePriceTier;
use App\Core\Changeset\Dto\SetPriceTier;
use App\Core\Changeset\Dto\SetProductCategories;
use App\Core\Changeset\Dto\SetProductFlags;
use App\Core\Changeset\Dto\SetTranslation;
use App\Core\Changeset\Dto\SetVariantPrice;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetNotApplicable;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Changeset\Plan\Issue;
use App\Core\Changeset\Validate\ConflictBlame;
use App\Core\Changeset\Validate\IssueMessage;

/**
 * Відкат = новий пакет з інверсними операціями (consilium §3): з diff застосованого пакета «стане» стає expect, «було» — новим
 * значенням; далі звичайний dry-run і застосування людиною. Якщо хтось змінив поле після — expect не зійдеться, і помилка
 * conflict скаже, хто й коли (з журналу). Операції без фактичних змін (noop) у відкат не потрапляють. Переклад, який пакет
 * створив (усі «було» — null), інверсією не знімається: revert_unsupported з поясненням, видалити його можна лише в адмінці.
 */
final class ChangesetReverter
{
    /** @var array<int, array{type: string, id: int|null, locale: string|null}> цілі інверсних операцій останнього inverse() */
    private array $targets = [];

    public function __construct(private readonly ChangesetPlanner $planner, private readonly ConflictBlame $blame)
    {
    }

    /** @return array<string, mixed> вхід для планувальника */
    public function inverse(Changeset $applied): array
    {
        if (ChangesetStatus::Applied !== $applied->getStatus()) {
            throw new ChangesetNotApplicable((int) $applied->getId(), $applied->effectiveStatus());
        }
        $operations = $applied->getOperations();
        $inverse = [];
        $targets = [];
        foreach ($applied->getDiff() as $diff) {
            $op = $operations[(int) $diff['index']] ?? null;
            if (null === $op || [] === $diff['changes']) {
                continue;
            }
            $before = $after = [];
            foreach ($diff['changes'] as $change) {
                $before[$change['field']] = $change['before'];
                $after[$change['field']] = $change['after'];
            }
            $locale = $diff['changes'][0]['locale'] ?? null;
            if (SetTranslation::TYPE === $diff['op'] && [] === array_filter($before, static fn (mixed $v): bool => null !== $v)) {
                throw new ChangesetValidationFailed([Issue::error((int) $diff['index'], 'revert_unsupported', IssueMessage::of('changeset.issue.revert_unsupported', ['locale' => (string) ($locale ?? $op['locale']), 'target' => (string) ($diff['target']['label'] ?? $diff['target']['type'])], \sprintf('Переклад «%s» для %s створено цим пакетом — відкат його не знімає; видалити переклад можна лише в адмінці', (string) ($locale ?? $op['locale']), (string) ($diff['target']['label'] ?? $diff['target']['type']))))]);
            }
            $inverted = match ($diff['op']) {
                SetVariantPrice::TYPE => [
                    'op' => SetVariantPrice::TYPE, 'variant' => $op['variant'],
                    'price' => $before['price'] ?? $op['price'], 'old_price' => \array_key_exists('old_price', $before) ? $before['old_price'] : $op['old_price'],
                    'expect' => ['price' => $op['price'], 'old_price' => $op['old_price']],
                ],
                SetPriceTier::TYPE => null === ($before['price'] ?? null)
                    ? ['op' => RemovePriceTier::TYPE, 'variant' => $op['variant'], 'min_quantity' => $op['min_quantity'], 'customer_group' => $op['customer_group'], 'expect' => ['price' => $op['price']]]
                    : ['op' => SetPriceTier::TYPE, 'variant' => $op['variant'], 'min_quantity' => $op['min_quantity'], 'customer_group' => $op['customer_group'], 'price' => $before['price'], 'expect' => ['price' => $op['price']]],
                RemovePriceTier::TYPE => ['op' => SetPriceTier::TYPE, 'variant' => $op['variant'], 'min_quantity' => $op['min_quantity'], 'customer_group' => $op['customer_group'], 'price' => $before['price'], 'expect' => ['price' => null]],
                SetTranslation::TYPE => ['op' => SetTranslation::TYPE, 'entity' => $op['entity'], 'id' => $op['id'], 'locale' => $locale ?? $op['locale'], 'fields' => $before, 'expect' => $after],
                SetProductFlags::TYPE => ['op' => SetProductFlags::TYPE, 'product' => $op['product']] + $before + ['expect' => $after],
                SetProductCategories::TYPE => [
                    'op' => SetProductCategories::TYPE, 'product' => $op['product'],
                    'categories' => $before['categories'] ?? $op['categories'], 'main' => $before['main'] ?? $op['main'],
                    'expect' => ['categories' => $op['categories'], 'main' => $op['main']],
                ],
                default => null,
            };
            if (null !== $inverted) {
                $targets[\count($inverse)] = ['type' => (string) $diff['target']['type'], 'id' => isset($diff['target']['id']) ? (int) $diff['target']['id'] : null, 'locale' => \is_string($locale) ? $locale : null];
                $inverse[] = $inverted;
            }
        }
        $this->targets = $targets;

        return ['title' => \sprintf('Відкат #%d: %s', (int) $applied->getId(), $applied->getTitle()), 'operations' => $inverse];
    }

    /**
     * Пакет відкату (proposed, revert_of = $applied); застосовує людина звичайним шляхом.
     *
     * @throws ChangesetValidationFailed з conflict і «хто/коли», якщо поля вже змінили
     */
    public function propose(Changeset $applied, ChangesetAuthor $author): Changeset
    {
        $input = $this->inverse($applied);
        if ([] === $input['operations']) {
            throw new ChangesetValidationFailed([Issue::error(null, 'empty', IssueMessage::of('changeset.issue.nothing_to_revert', [], 'Пакет нічого не змінив — відкочувати нема чого'))]);
        }
        try {
            return $this->planner->propose($input, $author, $applied);
        } catch (ChangesetValidationFailed $e) {
            throw new ChangesetValidationFailed($this->blame->enrich($e->issues, $this->targets, $applied->getAppliedAt() ?? $applied->getCreatedAt()));
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Changeset;

use App\Core\Audit\AuditSource;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Plan\Issue;
use App\Core\Changeset\Validate\IssueMessage;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Сторінка пакета (E15 PR-4, дизайн B pr4-ui.md §3): diff → рядки для шаблону з людськими назвами полів і типів, форматом
 * значень (гроші, bool, списки категорій назвами, HTML — лише текстом; сирий HTML у шаблоні завжди екранується),
 * відсотком зміни ціни; ризики згруповано за прапорцем з порогами з параметрів магазину; попередження й конфлікти — рядками.
 * Без залежностей від EasyAdmin: посилання на форми сутностей додає контролер. Усі підписи й тексти знахідок — мовою
 * адмінки (домен admin: changeset.target/field/warning/risk/value.*, знахідки — за ключем i18n, старі пакети — українською).
 */
final class ChangesetPresenter
{
    /** Типи цілей, поля й коди попереджень з підписами (changeset.target.*, changeset.field.*, changeset.warning.*); решта — кодом */
    private const array TYPES = ['product_variant', 'product', 'category', 'page', 'post', 'price_tier'];
    private const array FIELDS = ['price', 'old_price', 'name', 'title', 'short_description', 'description', 'body', 'meta_title', 'meta_description', 'slug', 'h1', 'excerpt', 'active', 'featured', 'categories', 'main'];
    private const array WARNINGS = ['html_sanitized', 'typography_dash', 'double_space', 'straight_quotes', 'tier_above_base', 'noop', 'slug_generated'];
    private const array MONEY_FIELDS = ['price', 'old_price'];
    private const array HTML_FIELDS = ['description', 'body'];
    private const int TEXT_PREVIEW = 300;
    /** Авто-301 при зміні slug роблять слухачі цих типів (SlugChangeListener); категорії — через CategoryPaths */
    private const array REDIRECT_TYPES = ['product', 'page', 'post'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.ai_price_jump_pct%')] private readonly int $jumpPct,
        #[Autowire('%app.ai_price_cumulative_pct%')] private readonly int $cumulativePct,
        #[Autowire('%app.ai_price_cumulative_days%')] private readonly int $cumulativeDays,
        #[Autowire('%app.ai_mass_price_ops%')] private readonly int $massOps,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, risks: list<array<string, mixed>>, warnings: list<array<string, mixed>>, risk_summary: string, price_ops: int}
     */
    public function present(Changeset $changeset): array
    {
        $categoryNames = $this->categoryNames($changeset->getDiff());
        $productOfVariant = $this->productIds($changeset->getDiff());
        $riskByIndex = [];
        foreach ($changeset->getWarnings() as $warning) {
            if (Issue::RISK === ($warning['kind'] ?? null) && null !== ($warning['index'] ?? null)) {
                $riskByIndex[(int) $warning['index']] = true;
            }
        }
        $rows = [];
        $priceOps = 0;
        foreach ($changeset->getDiff() as $diff) {
            $index = (int) $diff['index'];
            $target = $diff['target'];
            $type = (string) $target['type'];
            $isPrice = \in_array($diff['op'], ['set_variant_price', 'set_price_tier', 'remove_price_tier'], true);
            $priceOps += $isPrice ? 1 : 0;
            $changes = [];
            foreach ($diff['changes'] as $change) {
                $field = (string) $change['field'];
                $changes[] = [
                    'field' => $field,
                    'label' => $this->fieldLabel($field),
                    'locale' => $change['locale'] ?? null,
                    'before' => $this->value($field, $change['before'], $categoryNames),
                    'after' => $this->value($field, $change['after'], $categoryNames),
                    'before_full' => $this->fullText($field, $change['before']),
                    'after_full' => $this->fullText($field, $change['after']),
                    'html' => \in_array($field, self::HTML_FIELDS, true),
                    'mono' => 'slug' === $field,
                    'percent' => 'price' === $field && 'set_variant_price' === $diff['op'] ? self::percent($change['before'], $change['after']) : null,
                    'redirect' => 'slug' === $field && \in_array($type, self::REDIRECT_TYPES, true) && null !== $change['before'],
                    'category_paths' => 'slug' === $field && 'category' === $type,
                ];
            }
            $rows[] = [
                'index' => $index,
                'op' => $diff['op'],
                'type' => $type,
                'type_label' => $this->typeLabel($type),
                'id' => $target['id'] ?? null,
                'product_id' => match ($type) {
                    'product_variant' => $productOfVariant[(int) ($target['id'] ?? 0)] ?? null,
                    'price_tier' => $this->productOfTier($diff, $changeset->getOperations()),
                    'product' => $target['id'] ?? null,
                    default => null,
                },
                'label' => $target['label'] ?? null,
                'locale' => $diff['changes'][0]['locale'] ?? null,
                'risk' => isset($riskByIndex[$index]),
                'changes' => $changes,
            ];
        }
        [$risks, $summary] = $this->risks($changeset);

        return ['rows' => $rows, 'risks' => $risks, 'warnings' => $this->warnings($changeset), 'risk_summary' => $summary, 'price_ops' => $priceOps];
    }

    /**
     * Конфлікти при застосуванні (409) — рядки для таблиці §4.1.
     *
     * @param list<Issue> $issues
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(array $issues, Changeset $changeset): array
    {
        $labels = [];
        foreach ($changeset->getDiff() as $diff) {
            $labels[(int) $diff['index']] = $this->typeLabel((string) $diff['target']['type']).' '.($diff['target']['label'] ?? '#'.$diff['target']['id']);
        }
        $categoryNames = $this->categoryNames($changeset->getDiff());
        $out = [];
        foreach ($issues as $issue) {
            $field = (string) ($issue->details['field'] ?? '');
            $who = $issue->details['changed_by'] ?? null;
            $out[] = [
                'index' => $issue->index,
                'code' => $issue->code,
                'target' => null === $issue->index ? null : ($labels[$issue->index] ?? null),
                'field' => '' === $field ? null : $this->fieldLabel($field),
                'expected' => \array_key_exists('expected', $issue->details) ? $this->value($field, $issue->details['expected'], $categoryNames) : null,
                'current' => \array_key_exists('current', $issue->details) ? $this->value($field, $issue->details['current'], $categoryNames) : null,
                'who' => \is_string($who) ? $who : (isset($issue->details['source']) ? (AuditSource::tryFrom((string) $issue->details['source'])?->trans($this->translator) ?? (string) $issue->details['source']) : null),
                'when' => $issue->details['changed_at'] ?? null,
                'message' => $issue->text()->trans($this->translator),
            ];
        }

        return $out;
    }

    /** Назва прапорця з порогами з параметрів магазину (не константи в шаблоні). */
    public function riskLabel(string $code): string
    {
        // числа рядками: ICU не групує розряди, текст той самий, що й у знахідках
        return match ($code) {
            'price_jump' => $this->translator->trans('changeset.risk.price_jump', ['pct' => (string) $this->jumpPct], 'admin'),
            'cumulative' => $this->translator->trans('changeset.risk.cumulative', ['days' => (string) $this->cumulativeDays, 'pct' => (string) $this->cumulativePct], 'admin'),
            'zero_price' => $this->translator->trans('changeset.risk.zero_price', [], 'admin'),
            'mass_price' => $this->translator->trans('changeset.risk.mass_price', ['count' => (string) $this->massOps], 'admin'),
            default => $code,
        };
    }

    /** @return array{list<array<string, mixed>>, string} групи ризиків і підпис для чекбокса */
    private function risks(Changeset $changeset): array
    {
        $groups = [];
        foreach ($changeset->getWarnings() as $warning) {
            if (Issue::RISK !== ($warning['kind'] ?? null)) {
                continue;
            }
            $code = (string) $warning['code'];
            $groups[$code]['code'] = $code;
            $groups[$code]['label'] = $this->riskLabel($code);
            $groups[$code]['items'][] = ['index' => $warning['index'] ?? null, 'message' => $this->message($warning)];
        }
        // прапорці без знахідки по операції (mass_price — про пакет) теж показуються
        foreach ($changeset->getRiskFlags() as $code) {
            $groups[$code] ??= ['code' => $code, 'label' => $this->riskLabel($code), 'items' => []];
        }
        $parts = [];
        foreach ($groups as $group) {
            $n = \count(array_filter($group['items'], static fn (array $i): bool => null !== $i['index']));
            $parts[] = $n > 0 ? $this->translator->trans('changeset.risk.summary_ops', ['label' => mb_strtolower($group['label']), 'count' => (string) $n], 'admin') : mb_strtolower($group['label']);
        }

        return [array_values($groups), implode(', ', $parts)];
    }

    /** @return list<array<string, mixed>> */
    private function warnings(Changeset $changeset): array
    {
        $out = [];
        foreach ($changeset->getWarnings() as $warning) {
            if (Issue::WARNING !== ($warning['kind'] ?? null)) {
                continue;
            }
            $code = (string) $warning['code'];
            $out[] = ['index' => $warning['index'] ?? null, 'code' => $code, 'label' => \in_array($code, self::WARNINGS, true) ? $this->translator->trans('changeset.warning.'.$code, [], 'admin') : $code, 'message' => $this->message($warning)];
        }

        return $out;
    }

    /** @param array<int, string> $categoryNames */
    private function value(string $field, mixed $value, array $categoryNames): ?string
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }
        if (\in_array($field, self::MONEY_FIELDS, true) && \is_string($value)) {
            return self::money($value);
        }
        if (\is_bool($value)) {
            return $this->translator->trans($value ? 'changeset.value.yes' : 'changeset.value.no', [], 'admin');
        }
        if ('categories' === $field && \is_array($value)) {
            return implode('; ', array_map(fn (mixed $id): string => $categoryNames[(int) $id] ?? $this->deletedCategory((int) $id), $value));
        }
        if ('main' === $field && (\is_int($value) || \is_string($value))) {
            return $categoryNames[(int) $value] ?? $this->deletedCategory((int) $value);
        }
        if (\in_array($field, self::HTML_FIELDS, true) && \is_string($value)) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));

            return mb_strlen($text) > self::TEXT_PREVIEW ? mb_substr($text, 0, self::TEXT_PREVIEW).'…' : $text;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    private function typeLabel(string $type): string
    {
        return \in_array($type, self::TYPES, true) ? $this->translator->trans('changeset.target.'.$type, [], 'admin') : $type;
    }

    private function fieldLabel(string $field): string
    {
        return \in_array($field, self::FIELDS, true) ? $this->translator->trans('changeset.field.'.$field, [], 'admin') : $field;
    }

    private function deletedCategory(int $id): string
    {
        return $this->translator->trans('changeset.value.deleted_category', ['id' => (string) $id], 'admin');
    }

    /**
     * Текст збереженої знахідки мовою адмінки: за ключем i18n (ChangesetPlan::warningsArray), у старих пакетах — український.
     *
     * @param array<string, mixed> $warning
     */
    private function message(array $warning): string
    {
        return IssueMessage::fromArray((string) ($warning['message'] ?? ''), $warning['i18n'] ?? null)->trans($this->translator);
    }

    /** Повний текст HTML-поля (екранується в шаблоні) і повний текстовий варіант */
    private function fullText(string $field, mixed $value): ?string
    {
        return \in_array($field, self::HTML_FIELDS, true) && \is_string($value) && '' !== $value ? $value : null;
    }

    private static function money(string $decimal): string
    {
        try {
            $money = Money::fromDecimalString($decimal, 'XXX');
        } catch (InvalidArgumentException) {
            return $decimal;
        }
        $units = intdiv($money->amount, 100);
        $cents = abs($money->amount % 100);

        return number_format($units, 0, ',', ' ').','.str_pad((string) $cents, 2, '0', \STR_PAD_LEFT);
    }

    private static function percent(mixed $before, mixed $after): ?string
    {
        if (!\is_string($before) || !\is_string($after)) {
            return null;
        }
        try {
            $from = Money::fromDecimalString($before, 'XXX')->amount;
            $to = Money::fromDecimalString($after, 'XXX')->amount;
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($from <= 0) {
            return null;
        }
        $pct = (int) round(($to - $from) * 100 / $from);

        return ($pct >= 0 ? '+' : '−').abs($pct).' %';
    }

    /**
     * Назви категорій з diff (одна вибірка на сторінку).
     *
     * @param list<array<string, mixed>> $diff
     *
     * @return array<int, string>
     */
    private function categoryNames(array $diff): array
    {
        $ids = [];
        foreach ($diff as $entry) {
            foreach ($entry['changes'] as $change) {
                foreach (['before', 'after'] as $side) {
                    $value = $change[$side];
                    if ('categories' === $change['field'] && \is_array($value)) {
                        array_push($ids, ...array_map('intval', $value));
                    } elseif ('main' === $change['field'] && null !== $value) {
                        $ids[] = (int) $value;
                    }
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }
        $names = [];
        /** @var list<Category> $categories */
        $categories = $this->em->getRepository(Category::class)->findBy(['id' => $ids]);
        foreach ($categories as $category) {
            $t = null;
            foreach ($category->getTranslatedLocales() as $locale) {
                $t ??= $category->getTranslation($locale);
            }
            $names[(int) $category->getId()] = $t instanceof CategoryTranslation ? $t->getName() : '#'.$category->getId();
        }

        return $names;
    }

    /**
     * Товар кожного варіанта з diff — для посилання на форму (варіант редагується у формі товару).
     *
     * @param list<array<string, mixed>> $diff
     *
     * @return array<int, int>
     */
    private function productIds(array $diff): array
    {
        $ids = [];
        foreach ($diff as $entry) {
            if ('product_variant' === $entry['target']['type'] && null !== ($entry['target']['id'] ?? null)) {
                $ids[] = (int) $entry['target']['id'];
            }
        }
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: int, pid: int}> $rows */
        $rows = $this->em->createQueryBuilder()->select('v.id AS id, IDENTITY(v.product) AS pid')->from(ProductVariant::class, 'v')->where('v.id IN (:ids)')->setParameter('ids', array_unique($ids))->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (int) $row['pid'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $diff
     * @param list<array<string, mixed>> $operations
     */
    private function productOfTier(array $diff, array $operations): ?int
    {
        $op = $operations[(int) $diff['index']] ?? null;
        $ref = \is_array($op) ? ($op['variant'] ?? null) : null;
        if (null === $ref) {
            return null;
        }
        $variant = \is_int($ref) ? $this->em->find(ProductVariant::class, $ref) : $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => (string) $ref]);

        return $variant?->getProduct()->getId();
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Audit\AuditedEntities;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Changeset\Dto\Operation;
use App\Core\Changeset\Dto\RemovePriceTier;
use App\Core\Changeset\Dto\SetPriceTier;
use App\Core\Changeset\Dto\SetProductCategories;
use App\Core\Changeset\Dto\SetProductFlags;
use App\Core\Changeset\Dto\SetTranslation;
use App\Core\Changeset\Dto\SetVariantPrice;
use App\Core\Changeset\Plan\ChangesetPlan;
use App\Core\Changeset\Plan\Issue;
use App\Core\Changeset\Plan\OperationDiff;
use App\Core\Changeset\Resolve\ChangesetResolver;
use App\Core\Changeset\Resolve\ResolvedTargets;
use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Серце dry-run і повторної перевірки при застосуванні: для кожної операції — сутність (not_found), expect (conflict),
 * сеттери домену на завантажених сутностях БЕЗ flush (інваріанти кидають — domain), контент (HTML, довжини, слаг, типографіка),
 * цінові правила (ризики), diff. Після evaluate() сутності в EntityManager змінені: dry-run їх очищає (clear), аплаєр робить
 * flush під актором журналу. Flush з rollback тут свідомо не використовується: postFlush-слухачі (read-index, авто-301, аудит,
 * черга) мають побічні ефекти поза транзакцією.
 * Слаг перекладу (рев'ю C #72, M4): новий переклад або переклад без адреси, де slug не задано, отримує слаг з назви тим самим
 * слагером, що й форма адмінки (попередження slug_generated); зняти наявний slug порожнім значенням не можна — лише змінити.
 * Тексти знахідок — IssueMessage: український рядок іде в API як і раніше, ключ changeset.issue.* — на сторінку пакета.
 */
final class ChangesetEvaluator
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChangesetResolver $resolver,
        private readonly ContentRules $content,
        private readonly PriceRules $prices,
        private readonly CatalogSlugs $slugs,
        #[Target('urlSlugger')] private readonly SluggerInterface $slugger,
        #[Autowire('%app.locales%')] private readonly array $locales,
    ) {
    }

    /** @param list<Operation> $operations */
    public function evaluate(string $title, array $operations, bool $lock = false): ChangesetPlan
    {
        $plan = new ChangesetPlan($title);
        $targets = $this->resolver->load($operations, $lock);
        $seen = [];
        foreach ($operations as $index => $op) {
            $issues = [];
            $diff = $this->apply($index, $op, $targets, $issues, $seen);
            $normalized = $op->toArray();
            if (null !== $diff) {
                $normalized['target'] = ['type' => $diff->targetType, 'id' => $diff->targetId, 'label' => $diff->targetLabel];
                $plan->addDiff($diff);
                if ($diff->isEmpty() && [] === array_filter($issues, static fn (Issue $i): bool => Issue::ERROR === $i->kind)) {
                    $issues[] = Issue::warning($index, 'noop', IssueMessage::of('changeset.issue.noop', [], 'Операція нічого не змінює'));
                }
            }
            $plan->addOperation($normalized, $op->isPrice());
            foreach ($issues as $issue) {
                $plan->add($issue);
            }
        }
        $mass = $this->prices->massPrice($plan->priceOperations());
        if (null !== $mass) {
            $plan->add($mass);
        }

        return $plan;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen   ключ цілі → індекс операції, що вже її змінює
     */
    private function apply(int $index, Operation $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        return match (true) {
            $op instanceof SetVariantPrice => $this->variantPrice($index, $op, $targets, $issues, $seen),
            $op instanceof SetPriceTier => $this->priceTier($index, $op, $targets, $issues, $seen),
            $op instanceof RemovePriceTier => $this->removeTier($index, $op, $targets, $issues, $seen),
            $op instanceof SetTranslation => $this->translation($index, $op, $targets, $issues, $seen),
            $op instanceof SetProductFlags => $this->flags($index, $op, $targets, $issues, $seen),
            $op instanceof SetProductCategories => $this->categories($index, $op, $targets, $issues, $seen),
            default => null,
        };
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function variantPrice(int $index, SetVariantPrice $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $variant = $targets->variant($op->variant);
        if (null === $variant) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.variant_not_found', ['ref' => self::ref($op->variant)], \sprintf('Варіант %s не знайдено', self::ref($op->variant))));

            return null;
        }
        $diff = new OperationDiff($index, $op->type(), 'product_variant', $variant->getId(), $variant->getSku());
        if (!$this->once($index, 'variant_price:'.$variant->getId(), $seen, $issues)) {
            return $diff;
        }
        $beforePrice = $variant->getPrice();
        $beforeOld = $variant->getOldPrice();
        $current = ['price' => $beforePrice->toDecimalString(), 'old_price' => $beforeOld?->toDecimalString()];
        if (!$this->expect($index, $op, ['price', 'old_price'], $current, 'money', $issues)) {
            return $diff;
        }
        try {
            $price = Money::fromDecimalString($op->price, $variant->getCurrency());
            $old = null === $op->oldPrice ? null : Money::fromDecimalString($op->oldPrice, $variant->getCurrency());
            $variant->setPrice($price, $old);
        } catch (CatalogInvariantViolation|InvalidArgumentException $e) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::fromException($e));

            return $diff;
        }
        $diff->change('price', $current['price'], $price->toDecimalString());
        $diff->change('old_price', $current['old_price'], $old?->toDecimalString());
        if ($price->amount !== $beforePrice->amount) {
            array_push($issues, ...$this->prices->variantPrice($index, (int) $variant->getId(), $variant->getSku(), $beforePrice->amount, $price->amount));
        }

        return $diff;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function priceTier(int $index, SetPriceTier $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $variant = $targets->variant($op->variant);
        if (null === $variant) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.variant_not_found', ['ref' => self::ref($op->variant)], \sprintf('Варіант %s не знайдено', self::ref($op->variant))));

            return null;
        }
        $group = null;
        if (null !== $op->customerGroup) {
            $group = $targets->group($op->customerGroup);
            if (null === $group) {
                $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.group_not_found', ['code' => $op->customerGroup], \sprintf('Групу покупців «%s» не знайдено', $op->customerGroup)));

                return null;
            }
        }
        try {
            $minQuantity = Quantity::positive($op->minQuantity);
        } catch (InvalidArgumentException $e) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::fromException($e));

            return null;
        }
        $tier = self::findTier($variant, $minQuantity, $op->customerGroup);
        $label = \sprintf('%s від %s%s', $variant->getSku(), Quantity::display($minQuantity), null === $group ? '' : ' ('.$group->getCode().')');
        $diff = new OperationDiff($index, $op->type(), 'price_tier', $tier?->getId(), $label);
        if (!$this->once($index, 'tier:'.$variant->getId().':'.$minQuantity.':'.($op->customerGroup ?? ''), $seen, $issues)) {
            return $diff;
        }
        $current = ['price' => $tier?->getPrice()->toDecimalString()];
        if (!$this->expect($index, $op, ['price'], $current, 'money', $issues)) {
            return $diff;
        }
        try {
            $price = Money::fromDecimalString($op->price, $variant->getCurrency());
            if (null === $tier) {
                $variant->addPriceTier($minQuantity, $price, $group);
            } else {
                $tier->changePrice($price);
            }
        } catch (CatalogInvariantViolation|InvalidArgumentException $e) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::fromException($e));

            return $diff;
        }
        $diff->change('price', $current['price'], $price->toDecimalString());
        $warning = $this->prices->tier($index, $variant->getSku(), Quantity::display($minQuantity), $price->amount, $variant->getPrice()->amount);
        if (null !== $warning) {
            $issues[] = $warning;
        }

        return $diff;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function removeTier(int $index, RemovePriceTier $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $variant = $targets->variant($op->variant);
        if (null === $variant) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.variant_not_found', ['ref' => self::ref($op->variant)], \sprintf('Варіант %s не знайдено', self::ref($op->variant))));

            return null;
        }
        try {
            $minQuantity = Quantity::positive($op->minQuantity);
        } catch (InvalidArgumentException $e) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::fromException($e));

            return null;
        }
        $tier = self::findTier($variant, $minQuantity, $op->customerGroup);
        if (null === $tier) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of(null === $op->customerGroup ? 'changeset.issue.tier_not_found' : 'changeset.issue.tier_not_found_group', ['qty' => Quantity::display($minQuantity), 'group' => $op->customerGroup, 'sku' => $variant->getSku()], \sprintf('Порога від %s%s у %s нема', Quantity::display($minQuantity), null === $op->customerGroup ? '' : ' для групи '.$op->customerGroup, $variant->getSku())));

            return null;
        }
        $diff = new OperationDiff($index, $op->type(), 'price_tier', $tier->getId(), AuditedEntities::label($tier));
        if (!$this->once($index, 'tier:'.$variant->getId().':'.$minQuantity.':'.($op->customerGroup ?? ''), $seen, $issues)) {
            return $diff;
        }
        $current = ['price' => $tier->getPrice()->toDecimalString()];
        if (!$this->expect($index, $op, ['price'], $current, 'money', $issues)) {
            return $diff;
        }
        $variant->removePriceTier($tier);
        $diff->change('price', $current['price'], null);

        return $diff;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function translation(int $index, SetTranslation $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $locale = AbstractTranslation::normalizeLocale($op->locale);
        if (!\in_array($locale, $this->locales, true)) {
            $issues[] = Issue::error($index, 'locale', IssueMessage::of('changeset.issue.locale', ['locale' => $op->locale, 'locales' => implode(', ', $this->locales)], \sprintf('Мови «%s» у магазині нема (%s)', $op->locale, implode(', ', $this->locales))));

            return null;
        }
        $root = $targets->translatable($op->entity, $op->id);
        if (null === $root) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.entity_not_found', ['type' => new IssueMessage(AuditedEntities::typeLabel($op->entity), 'entity_type.'.$op->entity), 'id' => (string) $op->id], \sprintf('%s #%d не знайдено', AuditedEntities::typeLabel($op->entity), $op->id)));

            return null;
        }
        $diff = new OperationDiff($index, $op->type(), $op->entity, $op->id, AuditedEntities::label($root));
        $fields = [];
        foreach ($op->fields as $field => $value) {
            $field = (string) $field;
            if (!TranslationFields::isAllowed($op->entity, $field)) {
                $issues[] = Issue::error($index, 'field_not_allowed', IssueMessage::of('changeset.issue.field_not_allowed', ['field' => $field, 'entity' => $op->entity, 'allowed' => implode(', ', TranslationFields::allowed($op->entity))], \sprintf('Поле «%s» для %s недоступне; дозволено: %s', $field, $op->entity, implode(', ', TranslationFields::allowed($op->entity)))));
                continue;
            }
            if (null !== $value && !\is_string($value)) {
                $issues[] = Issue::error($index, 'schema', IssueMessage::of('changeset.issue.string_or_null', ['field' => $field], \sprintf('%s: рядок або null', $field)));
                continue;
            }
            if (!$this->once($index, 'translation:'.$op->entity.':'.$op->id.':'.$locale.':'.$field, $seen, $issues)) {
                continue;
            }
            $fields[$field] = $value;
        }
        if ([] !== array_filter($issues, static fn (Issue $i): bool => Issue::ERROR === $i->kind)) {
            return $diff;
        }
        $translation = $root->getTranslation($locale);
        $required = TranslationFields::requiredField($op->entity);
        if (null === $translation) {
            if (!\array_key_exists($required, $fields) || null === $fields[$required] || '' === trim($fields[$required])) {
                $issues[] = Issue::error($index, 'translation_missing', IssueMessage::of('changeset.issue.translation_missing', ['locale' => $locale, 'field' => $required], \sprintf('Перекладу «%s» ще нема: у fields потрібне поле %s', $locale, $required)));

                return $diff;
            }
            $current = array_fill_keys(array_keys($fields), null);
        } else {
            $current = [];
            foreach (array_keys($fields) as $field) {
                $current[$field] = TranslationFields::get($op->entity, $translation, $field);
            }
        }
        if (!$this->expect($index, $op, array_keys($fields), $current, 'text', $issues)) {
            return $diff;
        }
        $after = [];
        foreach ($fields as $field => $value) {
            $after[$field] = $this->content->normalize($index, $op->entity, $field, $value, $issues);
            if (TranslationFields::isRequired($op->entity, $field) && null === $after[$field]) {
                $issues[] = Issue::error($index, 'required', IssueMessage::of('changeset.issue.required', ['field' => $field], \sprintf('%s: порожнє значення не допускається', $field)));
            }
        }
        $currentSlug = null === $translation ? null : TranslationFields::get($op->entity, $translation, 'slug');
        if (\array_key_exists('slug', $after) && null === $after['slug'] && null !== $currentSlug) {
            $issues[] = Issue::error($index, 'required', IssueMessage::of('changeset.issue.slug_required', [], 'slug: адресу не можна зняти, лише змінити'));
        }
        $title = $after[$required] ?? (null === $translation ? null : TranslationFields::get($op->entity, $translation, $required));
        if (null === ($after['slug'] ?? null) && null === $currentSlug && null !== $title && '' !== trim($title)) {
            // без slug сторінки не існує; форма адмінки (AbstractTranslationType, SlugListener) робить те саме з назви
            $generated = mb_substr(trim($this->slugger->slug($title, '-', $locale)->lower()->toString(), '-'), 0, 180);
            if ('' !== $generated) {
                $after['slug'] = $generated;
                $current['slug'] = $currentSlug;
                $issues[] = Issue::warning($index, 'slug_generated', IssueMessage::of('changeset.issue.slug_generated', ['slug' => $generated], \sprintf('slug не задано — адресу «%s» утворено з назви', $generated)));
            }
        }
        if (\array_key_exists('slug', $after) && null !== $after['slug'] && $after['slug'] !== $current['slug']) {
            $conflict = $this->slugConflict($op->entity, $root, $locale, $after['slug']);
            if (null !== $conflict) {
                $issues[] = Issue::error($index, 'slug_taken', IssueMessage::of('changeset.issue.slug_taken', ['message' => $conflict], 'slug: '.$conflict));
            }
        }
        if ([] !== array_filter($issues, static fn (Issue $i): bool => Issue::ERROR === $i->kind)) {
            return $diff;
        }
        $translation ??= $root->translate($locale);
        foreach ($after as $field => $value) {
            TranslationFields::set($op->entity, $translation, $field, $value);
            $diff->change($field, $current[$field], $value, $locale);
        }

        return $diff;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function flags(int $index, SetProductFlags $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $product = $targets->product($op->product);
        if (null === $product) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.product_not_found', ['ref' => self::ref($op->product)], \sprintf('Товар %s не знайдено', self::ref($op->product))));

            return null;
        }
        $diff = new OperationDiff($index, $op->type(), 'product', $product->getId(), AuditedEntities::label($product));
        $flags = $op->flags();
        if ([] === $flags) {
            $issues[] = Issue::error($index, 'schema', IssueMessage::of('changeset.issue.flags_missing', [], 'Задайте active і/або featured'));

            return $diff;
        }
        foreach (array_keys($flags) as $flag) {
            if (!$this->once($index, 'flag:'.$product->getId().':'.$flag, $seen, $issues)) {
                return $diff;
            }
        }
        $current = [];
        foreach (array_keys($flags) as $flag) {
            $current[$flag] = 'active' === $flag ? $product->isActive() : $product->isFeatured();
        }
        if (!$this->expect($index, $op, array_keys($flags), $current, 'bool', $issues)) {
            return $diff;
        }
        foreach ($flags as $flag => $value) {
            'active' === $flag ? $product->setActive($value) : $product->setFeatured($value);
            $diff->change($flag, $current[$flag], $value);
        }

        return $diff;
    }

    /**
     * @param list<Issue>        $issues
     * @param array<string, int> $seen
     */
    private function categories(int $index, SetProductCategories $op, ResolvedTargets $targets, array &$issues, array &$seen): ?OperationDiff
    {
        $product = $targets->product($op->product);
        if (null === $product) {
            $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.product_not_found', ['ref' => self::ref($op->product)], \sprintf('Товар %s не знайдено', self::ref($op->product))));

            return null;
        }
        $diff = new OperationDiff($index, $op->type(), 'product', $product->getId(), AuditedEntities::label($product));
        if (!$this->once($index, 'categories:'.$product->getId(), $seen, $issues)) {
            return $diff;
        }
        $wanted = [];
        foreach (array_unique([...$op->categories, $op->main]) as $id) {
            $category = $targets->category($id);
            if (null === $category) {
                $issues[] = Issue::error($index, 'not_found', IssueMessage::of('changeset.issue.category_not_found', ['id' => (string) $id], \sprintf('Категорію #%d не знайдено', $id)));
                continue;
            }
            $wanted[$id] = $category;
        }
        if (!\in_array($op->main, $op->categories, true)) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::of('changeset.issue.main_not_in_categories', [], 'Головна категорія має бути серед categories'));
        }
        if ([] !== array_filter($issues, static fn (Issue $i): bool => Issue::ERROR === $i->kind)) {
            return $diff;
        }
        $currentIds = array_map(static fn (Category $c): int => (int) $c->getId(), $product->getCategories()->toArray());
        sort($currentIds);
        $current = ['categories' => $currentIds, 'main' => $product->getMainCategory()?->getId()];
        if (!$this->expect($index, $op, ['categories', 'main'], $current, 'ids', $issues)) {
            return $diff;
        }
        foreach ($product->getCategories()->toArray() as $category) {
            if (!isset($wanted[(int) $category->getId()])) {
                $product->removeCategory($category);
            }
        }
        foreach ($wanted as $category) {
            $product->addCategory($category);
        }
        try {
            $product->setMainCategory($wanted[$op->main]);
        } catch (CatalogInvariantViolation $e) {
            $issues[] = Issue::error($index, 'domain', IssueMessage::fromException($e));

            return $diff;
        }
        $newIds = array_keys($wanted);
        sort($newIds);
        $diff->change('categories', $currentIds, $newIds);
        $diff->change('main', $current['main'], $op->main);

        return $diff;
    }

    /**
     * Compare-and-set: кожен змінюваний ключ має бути в expect і збігатися з поточним значенням.
     *
     * @param list<string>         $keys
     * @param array<string, mixed> $current
     * @param list<Issue>          $issues
     */
    private function expect(int $index, Operation $op, array $keys, array $current, string $kind, array &$issues): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $op->expect)) {
                $issues[] = Issue::error($index, 'expect_missing', IssueMessage::of('changeset.issue.expect_missing', ['key' => $key], \sprintf('expect.%s: вкажіть поточне значення', $key)));
                $ok = false;
                continue;
            }
            $expected = $op->expect[$key];
            if (!self::same($kind, $expected, $current[$key])) {
                $issues[] = Issue::error($index, 'conflict', IssueMessage::of('changeset.issue.conflict', ['key' => $key], \sprintf('expect.%s не збігається з поточним значенням', $key)), ['field' => $key, 'expected' => $expected, 'current' => $current[$key]]);
                $ok = false;
            }
        }

        return $ok;
    }

    private static function same(string $kind, mixed $expected, mixed $current): bool
    {
        if (null === $expected || null === $current) {
            return self::blank($expected) && self::blank($current);
        }

        return match ($kind) {
            'money' => (\is_string($expected) || \is_int($expected) || \is_float($expected)) && \is_string($current) && self::moneyEquals((string) $expected, $current),
            'bool' => \is_bool($expected) && $expected === $current,
            'ids' => self::idsEqual($expected, $current),
            default => \is_string($expected) && \is_string($current) && trim($expected) === trim($current),
        };
    }

    private static function blank(mixed $value): bool
    {
        return null === $value || '' === $value || (\is_string($value) && '' === trim($value)) || [] === $value;
    }

    private static function moneyEquals(string $a, string $b): bool
    {
        try {
            return Money::fromDecimalString($a, 'XXX')->equals(Money::fromDecimalString($b, 'XXX'));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function idsEqual(mixed $expected, mixed $current): bool
    {
        if (\is_int($expected) || \is_int($current)) {
            return $expected === $current;
        }
        if (!\is_array($expected) || !\is_array($current)) {
            return false;
        }
        $expected = array_values(array_unique(array_map('intval', $expected)));
        sort($expected);

        return $expected === $current;
    }

    /**
     * Дві операції на одну ціль — друга не знає результату першої, тому відхиляємо.
     *
     * @param array<string, int> $seen
     * @param list<Issue>        $issues
     */
    private function once(int $index, string $key, array &$seen, array &$issues): bool
    {
        if (isset($seen[$key])) {
            $issues[] = Issue::error($index, 'duplicate_target', IssueMessage::of('changeset.issue.duplicate_target', ['op' => (string) $seen[$key]], \sprintf('Ту саму ціль уже змінює операція %d', $seen[$key])));

            return false;
        }
        $seen[$key] = $index;

        return true;
    }

    private static function findTier(ProductVariant $variant, string $minQuantity, ?string $groupCode): ?PriceTier
    {
        foreach ($variant->getPriceTiers() as $tier) {
            $tierGroup = $tier->getCustomerGroup();
            $sameGroup = null === $groupCode ? null === $tierGroup : (null !== $tierGroup && $tierGroup->getCode() === $groupCode);
            if ($sameGroup && 0 === Quantity::compare($tier->getMinQuantity(), $minQuantity)) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Чому слаг зайнятий (null — вільний): товари й категорії — CatalogSlugs (ключі error.catalog_slug.*), сторінки й записи —
     * унікальність у межах мови.
     */
    private function slugConflict(string $type, object $root, string $locale, string $slug): ?IssueMessage
    {
        if ($root instanceof Product) {
            $problem = $this->slugs->productProblem($slug, $locale, $root->getId());

            return null === $problem ? null : IssueMessage::fromException($problem);
        }
        if ($root instanceof Category) {
            $parent = $root->getParent();
            $parentPath = null;
            if (null !== $parent) {
                $parentTranslation = $parent->getTranslation($locale);
                $parentPath = $parentTranslation instanceof CategoryTranslation ? $parentTranslation->getPath() : null;
                if (null === $parentPath) {
                    return null;
                }
            }

            $problem = $this->slugs->categoryProblem($slug, $locale, $parentPath, $root->getId());

            return null === $problem ? null : IssueMessage::fromException($problem);
        }
        [$class, $owner] = TranslationFields::PAGE === $type ? [PageTranslation::class, 'page'] : [PostTranslation::class, 'post'];
        $taken = (int) $this->em->createQueryBuilder()->select('COUNT(t.id)')->from($class, 't')
            ->where('t.locale = :locale')->andWhere('t.slug = :slug')->andWhere(\sprintf('IDENTITY(t.%s) != :owner', $owner))
            ->setParameter('locale', $locale)->setParameter('slug', $slug)->setParameter('owner', method_exists($root, 'getId') ? $root->getId() : 0)
            ->getQuery()->getSingleScalarResult();

        return $taken > 0 ? IssueMessage::of('changeset.issue.slug_taken_locale', ['slug' => $slug], \sprintf('Слаг «%s» уже зайнятий цією мовою', $slug)) : null;
    }

    private static function ref(int|string $ref): string
    {
        return \is_int($ref) ? '#'.$ref : '«'.$ref.'»';
    }
}

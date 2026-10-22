<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Entity\OptionGroupTranslation;
use App\Core\Catalog\Entity\OptionValueTranslation;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\ModifierType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\LineQuote;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;

/**
 * Форма опцій на картці без JS (ADR Rev 5/9): групи з значеннями (radio/checkbox) або числом, відмітки з query
 * (кнопка «порахувати» — GET на ту саму сторінку) або за замовчуванням, і ціна одиниці з опціями від фасаду
 * Pricing (з акцією: price — до сплати, oldPrice — до акції; те саме число, що потім у кошику). Ті самі поля
 * options[…] форма «в кошик» відправляє в CartController::add. Група покупця — явно ($group з CustomerContext), для
 * групи поруч роздрібна ціна з тими ж опціями (retailPrice), якщо інша.
 *
 * @phpstan-type Value array{code: string, label: string, selected: bool, hint: ?string}
 * @phpstan-type Group array{code: string, label: string, required: bool, multiple: bool, number: bool, values: list<Value>, value: ?string, min: ?string, max: ?string, step: ?string, unit: ?string, error: ?string}
 */
final readonly class ProductOptionsForm
{
    public function __construct(private Pricing $pricing)
    {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{groups: list<Group>, price: ?Money, oldPrice: ?Money, retailPrice: ?Money, quote: ?LineQuote, calculated: bool, errors: array<string, string>}|null null — у товару нема опцій
     */
    public function build(Product $product, ?ProductVariant $variant, string $locale, array $query, ?CustomerGroup $group): ?array
    {
        if ($product->getOptionGroups()->isEmpty()) {
            return null;
        }
        $input = \is_array($query['options'] ?? null) ? $query['options'] : [];
        $calculated = isset($query['calc']);
        $quote = $retail = null;
        $errors = [];
        if (null !== $variant) {
            try {
                $quote = $this->pricing->configure($variant, $variant->getMinQuantity(), $calculated ? $input : [], $locale, $group);
                $retail = null === $group ? null : $this->pricing->configure($variant, $variant->getMinQuantity(), $calculated ? $input : [], $locale, null)->unitPrice();
            } catch (InvalidOptionsException $e) {
                $errors = $calculated ? $e->errors : []; // до «порахувати» не лякаємо помилками обов'язкових груп
            }
        }

        $groups = [];
        foreach ($product->getOptionGroups() as $group) {
            $code = $group->getCode();
            $translation = $group->getTranslation($locale);
            $raw = $input[$code] ?? null;
            $chosen = match (true) {
                \is_array($raw) => array_map(strval(...), $raw),
                \is_string($raw) => [$raw],
                default => [],
            };
            $values = [];
            foreach ($group->getValues() as $value) {
                $label = $value->getTranslation($locale);
                $values[] = [
                    'code' => $value->getCode(),
                    'label' => $label instanceof OptionValueTranslation && '' !== $label->getLabel() ? $label->getLabel() : $value->getCode(),
                    'selected' => $calculated ? \in_array($value->getCode(), $chosen, true) : $value->isDefault(),
                    'hint' => self::hint($value->getKind(), $value->getModifierType(), $value->getAmount(), $value->getBasisPoints(), $locale, $value->getSelectionPrice()),
                ];
            }
            $groups[] = [
                'code' => $code,
                'label' => $translation instanceof OptionGroupTranslation && '' !== $translation->getName() ? $translation->getName() : $code,
                'required' => $group->isRequired(),
                'multiple' => OptionSelection::Multiple === $group->getSelection(),
                'number' => $group->isNumber(),
                'values' => $values,
                'value' => $group->isNumber() && [] !== $chosen ? $chosen[0] : null,
                'min' => self::trim($group->getNumberMin()), 'max' => self::trim($group->getNumberMax()), 'step' => self::trim($group->getNumberStep()),
                'unit' => $group->getNumberUnit(),
                'error' => $errors[$code] ?? null,
            ];
        }

        return ['groups' => $groups, 'price' => $quote?->unitPrice(), 'oldPrice' => $quote?->listPrice(), 'retailPrice' => null !== $retail && null !== $quote && !$retail->equals($quote->unitPrice()) ? $retail : null, 'quote' => $quote, 'calculated' => $calculated, 'errors' => $errors];
    }

    private static function hint(OptionValueKind $kind, ?ModifierType $type, ?Money $amount, ?int $basisPoints, string $locale, ?Money $selectionPrice = null): ?string
    {
        if (OptionValueKind::Component === $kind) {
            // фіксована ціна за весь вибір — показуємо так само, як доплати (UX-аудит S7: раніше ціну дописували в назву «(480 грн за комплект)»);
            // без неї ціна компонента залежить від групи покупця й акцій — її показує «Ціна з опціями»
            return null !== $selectionPrice && !$selectionPrice->isZero() ? '+'.$selectionPrice->format($locale) : null;
        }
        if (ModifierType::Percent === $type && null !== $basisPoints && 0 !== $basisPoints) {
            return \sprintf('%+g %%', $basisPoints / 100);
        }
        if (null !== $amount && !$amount->isZero()) {
            return ($amount->isNegative() ? '−' : '+').$amount->max($amount->negate())->format($locale);
        }

        return null;
    }

    private static function trim(?string $decimal): ?string
    {
        return null === $decimal ? null : rtrim(rtrim($decimal, '0'), '.');
    }
}

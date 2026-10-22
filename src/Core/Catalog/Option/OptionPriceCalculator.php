<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Option;

use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\OptionGroupTranslation;
use App\Core\Catalog\Entity\OptionValue;
use App\Core\Catalog\Entity\OptionValueTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\ModifierType;
use App\Core\Catalog\Enum\NumberOptionRule;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\DiscountResolver;
use App\Core\Pricing\PriceQuote;
use App\Core\Pricing\PriceResolver;
use App\Core\Shared\Money\Money;

/**
 * Калькулятор опцій (ADR Rev 9 Q2) — єдине місце розрахунку: форма на картці («порахувати»), додавання в кошик,
 * перерахунок кошика і фіксація в замовленні.
 *
 * Формула (консиліум 07.10.2026, ADR «Ціна рядка»):
 *   B = PriceResolver(variant, qty, group)                      — тир на кількість рядка
 *   S = B + Σ round(B × bp) + Σ absolute + Σ number              — предмет акції; %-модифікатори від B до знижки
 *   Q = DiscountResolver::quote(variant, S, pricingGroup)        — переможець/stackable на S; група — лише коли B груповий поріг
 *   C = Σ (selectionPrice ?? тир компонента × кількість на одиницю) — компоненти поза знижкою (чужі SKU, ціна вибору)
 *   ціна одиниці = Q.price + C; ціна до акції = S + C (лише коли є знижка)
 * number: per_unit = ставка × значення, above_threshold = ставка × max(0, значення − поріг); відсоток округлюється
 * окремо для кожного значення. S < 0 → помилка. Компонент без selectionPrice, який сам не можна замовити (ціна за
 * запитом, нема ціни), — помилка: невідома ціна не продається за 0.
 *
 * Вхід — код групи → код значення | список кодів (multiple) | число рядком. Незнайомі групи ігноруються; група без
 * вибору (ключа нема) бере значення за замовчуванням; обов'язкова без вибору й без default — помилка. Явний порожній
 * вибір ('' або [] / ['']) означає «без опції» і зберігається в selection як '' або [] — так само в кошику й замовленні.
 */
final class OptionPriceCalculator
{
    public const int SCHEMA_VERSION = 1;
    public const int CALCULATOR_VERSION = 2;

    public function __construct(private readonly PriceResolver $prices, private readonly DiscountResolver $discounts)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return OptionConfiguration|null null — у товару нема груп опцій або нічого не обрано (звичайний рядок)
     *
     * @throws InvalidOptionsException
     */
    public function configure(ProductVariant $variant, string $quantity, array $input, string $locale, ?CustomerGroup $customerGroup = null): ?OptionConfiguration
    {
        $product = $variant->getProduct();
        if ($product->getOptionGroups()->isEmpty()) {
            return null;
        }
        $resolved = $this->prices->resolve($variant, $quantity, $customerGroup);
        $base = $resolved->price;
        $currency = $base->currency;
        $modifiers = $number = $componentsTotal = Money::zero($currency);
        $errors = $groups = $components = $selection = [];

        foreach ($product->getOptionGroups() as $group) {
            $code = $group->getCode();
            $raw = $input[$code] ?? null;
            try {
                if ($group->isNumber()) {
                    $value = self::number($group, $raw);
                    if (null === $value) {
                        continue;
                    }
                    $contribution = self::numberContribution($group, $value, $currency);
                    $number = $number->add($contribution);
                    $selection[$code] = $value;
                    $groups[] = [
                        'code' => $code, 'inputType' => $group->getInputType()->value, 'label' => self::groupLabel($group, $locale),
                        'value' => $value, 'unit' => $group->getNumberUnit(), 'rule' => $group->getNumberRule()?->value, 'contributionMinor' => $contribution->amount,
                    ];
                    continue;
                }
                $values = self::values($group, $raw);
                if ([] === $values) {
                    if (null !== $raw) {
                        // явне «без опції» (порожній radio / зняті чекбокси) фіксується у виборі, інакше перерахунок
                        // у кошику й PlaceOrder знову підставив би значення за замовчуванням
                        $selection[$code] = OptionSelection::Multiple === $group->getSelection() ? [] : '';
                    }
                    continue;
                }
                $contribution = Money::zero($currency);
                foreach ($values as $value) {
                    if (OptionValueKind::Component === $value->getKind()) {
                        $component = self::component($value, $this->prices, $currency, $customerGroup);
                        $components[] = $component + ['group' => $code, 'code' => $value->getCode()];
                        $componentsTotal = $componentsTotal->add($component['price']);
                        continue;
                    }
                    $contribution = $contribution->add(ModifierType::Percent === $value->getModifierType()
                        ? $base->percentage((int) $value->getBasisPoints())
                        : ($value->getAmount() ?? Money::zero($currency)));
                }
                $modifiers = $modifiers->add($contribution);
                $codes = array_map(static fn (OptionValue $v): string => $v->getCode(), $values);
                $selection[$code] = OptionSelection::Multiple === $group->getSelection() ? $codes : $codes[0];
                $groups[] = [
                    'code' => $code, 'inputType' => $group->getInputType()->value, 'label' => self::groupLabel($group, $locale),
                    OptionSelection::Multiple === $group->getSelection() ? 'values' : 'value' => $selection[$code],
                    'labels' => array_map(static fn (OptionValue $v): string => self::valueLabel($v, $locale), $values),
                    'contributionMinor' => $contribution->amount,
                ];
            } catch (CatalogInvariantViolation $e) {
                $errors[$code] = $e->getMessage();
            }
        }
        if ([] !== $errors) {
            throw new InvalidOptionsException($errors);
        }
        if ([] === $selection) {
            return null;
        }

        $subject = $base->add($modifiers)->add($number);
        if ($subject->isNegative()) {
            throw new InvalidOptionsException(['' => 'Ціна з опціями не може бути від\'ємною']);
        }
        // група для акцій — лише коли B — груповий поріг (ResolvedPrice): роздрібна B з опціями отримує роздрібні акції
        $discounted = $this->discounts->quote($variant, $subject, $resolved->pricingGroup);
        $quote = new PriceQuote($subject->add($componentsTotal), $discounted->price->add($componentsTotal), $discounted->promotions);
        ksort($selection);
        $key = hash('xxh128', json_encode([$variant->getId(), $selection], \JSON_THROW_ON_ERROR));
        $snapshot = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'key' => $key,
            'variantId' => $variant->getId(),
            'locale' => $locale,
            'selection' => $selection,
            'groups' => $groups,
            'components' => array_map(static fn (array $c): array => [
                'group' => $c['group'], 'code' => $c['code'], 'variantId' => $c['variant']->getId(), 'sku' => $c['variant']->getSku(),
                'quantity' => $c['quantity'], 'priceMinor' => $c['price']->amount,
            ], $components),
            'pricing' => [
                'calculatorVersion' => self::CALCULATOR_VERSION, 'baseMinor' => $base->amount, 'modifiersMinor' => $modifiers->amount,
                'numberMinor' => $number->amount, 'subjectMinor' => $subject->amount, 'discountMinor' => $discounted->discount()->amount,
                'promotions' => $discounted->promotions, 'componentsMinor' => $componentsTotal->amount,
                'listMinor' => $quote->listPrice->amount, 'unitPriceMinor' => $quote->price->amount, 'currency' => $currency,
            ],
        ];

        return new OptionConfiguration($quote, $snapshot, $components, $key);
    }

    /**
     * Перерахунок збереженого вибору за поточним каталогом (кошик; у замовленні — фіксація в момент оформлення).
     * Підписи — мовою $locale (мова сторінки), без неї — мовою, якою вибір зроблено.
     *
     * @param array<string, mixed>|null $snapshot
     *
     * @throws InvalidOptionsException вибір більше не валідний (значення прибрали, межі змінились)
     */
    public function reconfigure(ProductVariant $variant, string $quantity, ?array $snapshot, ?CustomerGroup $customerGroup = null, ?string $locale = null): ?OptionConfiguration
    {
        if (null === $snapshot) {
            return null;
        }
        $selection = \is_array($snapshot['selection'] ?? null) ? $snapshot['selection'] : [];
        $locale ??= \is_string($snapshot['locale'] ?? null) ? $snapshot['locale'] : 'uk';
        $configuration = $this->configure($variant, $quantity, $selection, $locale, $customerGroup);
        if (null === $configuration && [] !== $selection) {
            throw new InvalidOptionsException(['' => 'Опції товару змінились — оберіть їх знову']);
        }

        return $configuration;
    }

    /** @return list<OptionValue> */
    private static function values(OptionGroup $group, mixed $raw): array
    {
        $codes = match (true) {
            \is_string($raw) && '' !== trim($raw) => [trim($raw)],
            \is_array($raw) => array_values(array_unique(array_filter(array_map(static fn (mixed $c): string => \is_scalar($c) ? trim((string) $c) : '', $raw), static fn (string $c): bool => '' !== $c))),
            default => [],
        };
        if ([] === $codes && null === $raw) {
            $defaults = $group->getDefaultValues();
            if ([] === $defaults && $group->isRequired()) {
                throw new CatalogInvariantViolation(\sprintf('Оберіть «%s»', $group->getCode()));
            }

            return $defaults;
        }
        if ([] === $codes) {
            if ($group->isRequired()) {
                throw new CatalogInvariantViolation(\sprintf('Оберіть «%s»', $group->getCode()));
            }

            return [];
        }
        if (OptionSelection::Single === $group->getSelection() && \count($codes) > 1) {
            throw new CatalogInvariantViolation('Можна обрати лише одне значення');
        }
        $values = [];
        foreach ($codes as $code) {
            $values[] = $group->findValue($code) ?? throw new CatalogInvariantViolation(\sprintf('Значення «%s» нема', $code));
        }

        return $values;
    }

    /** Число групи: у межах min…max, кратне кроку від min; порожньо — null (або помилка для обов'язкової). */
    private static function number(OptionGroup $group, mixed $raw): ?string
    {
        $raw = \is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        if ('' === $raw) {
            if ($group->isRequired()) {
                throw new CatalogInvariantViolation(\sprintf('Вкажіть «%s»', $group->getCode()));
            }

            return null;
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,4})?$/', $raw)) {
            throw new CatalogInvariantViolation('Не число');
        }
        $value = bcadd($raw, '0', 4);
        $min = (string) $group->getNumberMin();
        $max = (string) $group->getNumberMax();
        if (bccomp($value, $min, 4) < 0 || bccomp($value, $max, 4) > 0) {
            throw new CatalogInvariantViolation(\sprintf('Від %s до %s', self::trimZeros($min), self::trimZeros($max)));
        }
        if (0 !== bccomp(bcmod(bcsub($value, $min, 4), (string) $group->getNumberStep(), 4), '0', 4)) {
            throw new CatalogInvariantViolation(\sprintf('Крок %s', self::trimZeros((string) $group->getNumberStep())));
        }

        return self::trimZeros($value);
    }

    private static function numberContribution(OptionGroup $group, string $value, string $currency): Money
    {
        $rate = $group->getNumberRate() ?? Money::zero($currency);
        $amount = NumberOptionRule::AboveThreshold === $group->getNumberRule()
            ? bcsub($value, (string) $group->getNumberThreshold(), 4)
            : $value;

        return bccomp($amount, '0', 4) <= 0 ? Money::zero($currency) : $rate->multiplyByDecimal($amount);
    }

    /**
     * Ціна компонента на одиницю головного товару. Явна selectionPrice — ціна вибору, яку назвав адмін (кейс «монтаж
     * за запитом окремо, у комплекті 1500»). Без неї — лише ціна компонента, який сам продається (isOrderable: не
     * «за запитом»); інакше помилка, а не 0 (консиліум 07.10.2026, варіант (б) fail-closed). Перевірка на кожному
     * перерахунку закриває і зміну режиму ціни компонента після створення опції.
     *
     * @return array{variant: ProductVariant, quantity: string, price: Money}
     */
    private static function component(OptionValue $value, PriceResolver $prices, string $currency, ?CustomerGroup $customerGroup): array
    {
        $variant = $value->getComponentVariant();
        $quantity = (string) $value->getComponentQuantity();
        if (null === $variant || !$variant->isActive() || !$variant->getProduct()->isActive()) {
            throw new CatalogInvariantViolation(\sprintf('Компонент «%s» недоступний', $value->getCode()));
        }
        $price = $value->getSelectionPrice();
        if (null === $price) {
            if (!$variant->isOrderable()) {
                throw new CatalogInvariantViolation(\sprintf('Компонент «%s» — ціна за запитом', $value->getCode()));
            }
            $price = $prices->unitPrice($variant, $quantity, $customerGroup)->multiplyByDecimal($quantity);
        }
        if ($price->currency !== $currency) {
            throw new CatalogInvariantViolation('Компонент в іншій валюті');
        }

        return ['variant' => $variant, 'quantity' => $quantity, 'price' => $price];
    }

    private static function groupLabel(OptionGroup $group, string $locale): string
    {
        $translation = $group->getTranslation($locale);

        return $translation instanceof OptionGroupTranslation && '' !== $translation->getName() ? $translation->getName() : $group->getCode();
    }

    private static function valueLabel(OptionValue $value, string $locale): string
    {
        $translation = $value->getTranslation($locale);

        return $translation instanceof OptionValueTranslation && '' !== $translation->getLabel() ? $translation->getLabel() : $value->getCode();
    }

    private static function trimZeros(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}

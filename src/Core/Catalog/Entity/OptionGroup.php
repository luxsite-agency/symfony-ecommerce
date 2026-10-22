<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\NumberOptionRule;
use App\Core\Catalog\Enum\OptionInputType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Група опцій картки («Полотно», «Коробка», «Замок» — ADR Rev 6). Опції не є варіантами: власного SKU й залишку не мають,
 * вони лише конфігурують рядок кошика. Значення — modifier (націнка) або component (варіант-компонент × кількість).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_option_group')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_option_group_code', columns: ['product_id', 'code'])]
#[UniqueEntity(fields: ['product', 'code'], message: 'catalog.option_group.code.unique', errorPath: 'code')]
class OptionGroup implements TranslatableInterface
{
    /** @use TranslatableTrait<OptionGroupTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'optionGroups')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'catalog.option_group.code.not_blank')]
    private string $code;

    #[ORM\Column(length: 16, enumType: OptionSelection::class)]
    private OptionSelection $selection;

    #[ORM\Column]
    private bool $required;

    #[ORM\Column]
    private int $position = 0;

    /** ADR Rev 9 Q2: select — значення зі списку; number — число в межах, ціна за формулою, без OptionValue. */
    #[ORM\Column(length: 16, enumType: OptionInputType::class, options: ['default' => 'select'])]
    private OptionInputType $inputType = OptionInputType::Select;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 4, nullable: true)]
    private ?string $numberMin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 4, nullable: true)]
    private ?string $numberMax = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 4, nullable: true)]
    private ?string $numberStep = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $numberUnit = null;

    #[ORM\Column(length: 16, nullable: true, enumType: NumberOptionRule::class)]
    private ?NumberOptionRule $numberRule = null;

    /** Ставка у валюті товару за одиницю значення. */
    #[ORM\Column(nullable: true)]
    private ?int $numberRateMinor = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 4, nullable: true)]
    private ?string $numberThreshold = null;

    /** @var Collection<int, OptionValue> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: OptionValue::class, mappedBy: 'group', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $values;

    /** @var Collection<string, OptionGroupTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: OptionGroupTranslation::class, mappedBy: 'group', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /** @internal використовуйте Product::addOptionGroup() */
    public function __construct(Product $product, string $code, OptionSelection $selection, bool $required)
    {
        $this->product = $product;
        $this->code = $code;
        $this->selection = $selection;
        $this->required = $required;
        $this->values = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function changeCode(string $code): void
    {
        $code = MachineCode::assertValid($code);
        $other = $this->product->findOptionGroup($code);
        if (null !== $other && $other !== $this) {
            throw new CatalogInvariantViolation(\sprintf('Група опцій "%s" вже є в товарі', $code), 'error.option_group.exists', ['code' => $code]);
        }
        $this->code = $code;
    }

    public function getSelection(): OptionSelection
    {
        return $this->selection;
    }

    public function setSelection(OptionSelection $selection): void
    {
        if (OptionInputType::Number === $this->inputType && OptionSelection::Single !== $selection) {
            throw new CatalogInvariantViolation('Числова група — лише одиночний вибір', 'error.option_group.number_single_only');
        }
        if (OptionSelection::Single === $selection && \count($this->getDefaultValues()) > 1) {
            throw new CatalogInvariantViolation('Одиночний вибір не може мати кілька значень за замовчуванням', 'error.option_group.single_many_defaults');
        }
        $this->selection = $selection;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getInputType(): OptionInputType
    {
        return $this->inputType;
    }

    public function isNumber(): bool
    {
        return OptionInputType::Number === $this->inputType;
    }

    /**
     * Зробити групу числовою (ADR Rev 9 Q2): межі min ≤ max, крок > 0, ставка ≥ 0 у валюті товару, поріг — лише для
     * above_threshold. Значень (OptionValue) у числової групи нема, вибір одиночний.
     */
    public function configureNumber(string $min, string $max, string $step, NumberOptionRule $rule, Money $rate, ?string $unit = null, ?string $threshold = null): void
    {
        if (!$this->values->isEmpty()) {
            throw new CatalogInvariantViolation('Числова група не може мати значень — спершу приберіть їх', 'error.option_group.number_has_values');
        }
        [$min, $max, $step] = [self::decimal($min), self::decimal($max), self::decimal($step)];
        if (bccomp($min, $max, 4) > 0) {
            throw new CatalogInvariantViolation('Мінімум числової опції більший за максимум', 'error.option_group.number_min_gt_max');
        }
        if (bccomp($step, '0', 4) <= 0) {
            throw new CatalogInvariantViolation('Крок числової опції має бути більшим за нуль', 'error.option_group.number_step_positive');
        }
        if ($rate->currency !== $this->product->getCurrency()) {
            throw new CatalogInvariantViolation(\sprintf('Ставка має бути у валюті товару %s', $this->product->getCurrency()), 'error.option_group.rate_currency', ['currency' => $this->product->getCurrency()]);
        }
        if ($rate->isNegative()) {
            throw new CatalogInvariantViolation('Ставка числової опції не може бути від\'ємною', 'error.option_group.rate_negative');
        }
        if (NumberOptionRule::AboveThreshold === $rule) {
            if (null === $threshold) {
                throw new CatalogInvariantViolation('Для правила «понад поріг» потрібен поріг', 'error.option_group.threshold_required');
            }
            $threshold = self::decimal($threshold);
        } else {
            $threshold = null;
        }
        $this->inputType = OptionInputType::Number;
        $this->selection = OptionSelection::Single;
        $this->numberMin = $min;
        $this->numberMax = $max;
        $this->numberStep = $step;
        $this->numberRule = $rule;
        $this->numberRateMinor = $rate->amount;
        $this->numberThreshold = $threshold;
        $unit = null === $unit ? null : trim($unit);
        $this->numberUnit = '' === $unit ? null : $unit;
    }

    public function getNumberMin(): ?string
    {
        return $this->numberMin;
    }

    public function getNumberMax(): ?string
    {
        return $this->numberMax;
    }

    public function getNumberStep(): ?string
    {
        return $this->numberStep;
    }

    public function getNumberUnit(): ?string
    {
        return $this->numberUnit;
    }

    public function getNumberRule(): ?NumberOptionRule
    {
        return $this->numberRule;
    }

    public function getNumberRate(): ?Money
    {
        return null === $this->numberRateMinor ? null : Money::of($this->numberRateMinor, $this->product->getCurrency());
    }

    public function getNumberThreshold(): ?string
    {
        return $this->numberThreshold;
    }

    /** Націнка сумою у валюті товару (Петлі накладні +1230 грн). Від'ємна сума — знижка за опцію. */
    public function addAbsoluteModifier(string $code, Money $amount): OptionValue
    {
        if ($amount->currency !== $this->product->getCurrency()) {
            throw new CatalogInvariantViolation(\sprintf('Націнка має бути у валюті товару %s', $this->product->getCurrency()), 'error.option_group.modifier_currency', ['currency' => $this->product->getCurrency()]);
        }

        return $this->add(OptionValue::absoluteModifier($this, $this->uniqueCode($code), $amount));
    }

    /** Націнка відсотком від бази в базисних пунктах: 1500 = +15 % (нестандартний розмір). */
    public function addPercentModifier(string $code, int $basisPoints): OptionValue
    {
        if ($basisPoints <= -10_000) {
            throw new CatalogInvariantViolation('Знижка опцією не може сягати 100 %', 'error.option_group.discount_too_big');
        }

        return $this->add(OptionValue::percentModifier($this, $this->uniqueCode($code), $basisPoints));
    }

    /**
     * Компонент набору: реальний варіант іншого товару × кількість (Лиштва 2,5 шт), щоб склад/CRM резервували SKU.
     * Компонент — у валюті товару: рядок кошика рахується в одній валюті без конвертації (ADR Rev 9).
     * Ціна — ціна компонента × кількість, або $selectionPrice за весь вибір (не за одиницю) у тій самій валюті.
     */
    public function addComponent(string $code, ProductVariant $component, string $quantity, ?Money $selectionPrice = null): OptionValue
    {
        if ($component->getProduct() === $this->product) {
            throw new CatalogInvariantViolation('Компонентом не може бути варіант того самого товару', 'error.option_group.component_same_product');
        }
        $currency = $this->product->getCurrency();
        if ($component->getCurrency() !== $currency) {
            throw new CatalogInvariantViolation(\sprintf('Компонент %s у валюті %s, а товар — у %s', $component->getSku(), $component->getCurrency(), $currency), 'error.option_group.component_currency', ['sku' => $component->getSku(), 'component_currency' => $component->getCurrency(), 'currency' => $currency]);
        }
        // компонент «за запитом» без ціни вибору продався б за 0 — у рантаймі OptionPriceCalculator теж відмовляє (консиліум 07.10.2026)
        if (null === $selectionPrice && PriceMode::OnRequest === $component->getProduct()->getPriceMode()) {
            throw new CatalogInvariantViolation(\sprintf('Компонент %s — ціна за запитом: вкажіть ціну за весь вибір', $component->getSku()), 'error.option_group.component_price_on_request', ['sku' => $component->getSku()]);
        }
        if (null !== $selectionPrice) {
            if ($selectionPrice->isNegative()) {
                throw new CatalogInvariantViolation('Ціна компонента не може бути від\'ємною', 'error.option_group.component_price_negative');
            }
            if ($selectionPrice->currency !== $currency) {
                throw new CatalogInvariantViolation(\sprintf('Ціна компонента має бути у валюті товару %s', $currency), 'error.option_group.component_price_currency', ['currency' => $currency]);
            }
        }

        return $this->add(OptionValue::component($this, $this->uniqueCode($code), $component, Quantity::positive($quantity), $selectionPrice));
    }

    public function removeValue(OptionValue $value): void
    {
        $this->values->removeElement($value);
    }

    public function findValue(string $code): ?OptionValue
    {
        return $this->values->findFirst(static fn (int $_, OptionValue $v): bool => $v->getCode() === $code);
    }

    /** @return Collection<int, OptionValue> */
    public function getValues(): Collection
    {
        return $this->values;
    }

    /** Для одиночного вибору позначка за замовчуванням знімається з інших значень групи. */
    public function markDefault(OptionValue $value, bool $default = true): void
    {
        if (!$this->values->contains($value)) {
            throw new CatalogInvariantViolation('Значення не з цієї групи', 'error.option_group.foreign_value');
        }
        if ($default && OptionSelection::Single === $this->selection) {
            foreach ($this->values as $other) {
                $other->setDefault(false);
            }
        }
        $value->setDefault($default);
    }

    /** @return list<OptionValue> */
    public function getDefaultValues(): array
    {
        return array_values(array_filter($this->values->toArray(), static fn (OptionValue $v): bool => $v->isDefault()));
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new OptionGroupTranslation($this, $locale);
    }

    private function uniqueCode(string $code): string
    {
        $code = MachineCode::assertValid($code);
        if (null !== $this->findValue($code)) {
            throw new CatalogInvariantViolation(\sprintf('Значення "%s" вже є в групі "%s"', $code, $this->code), 'error.option_group.value_exists', ['value' => $code, 'group' => $this->code]);
        }

        return $code;
    }

    /** "1,5" → "1.5000" (DECIMAL(16,4)); не число — CatalogInvariantViolation. */
    private static function decimal(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $value)) {
            throw new CatalogInvariantViolation(\sprintf('Не число DECIMAL(16,4): "%s"', $value), 'error.number.invalid_decimal', ['value' => $value]);
        }

        return bcadd($value, '0', 4);
    }

    private function add(OptionValue $value): OptionValue
    {
        if ($this->isNumber()) {
            throw new CatalogInvariantViolation('Числова група не має значень', 'error.option_group.number_no_values');
        }
        $value->setPosition($this->values->count());
        $this->values->add($value);

        return $value;
    }
}

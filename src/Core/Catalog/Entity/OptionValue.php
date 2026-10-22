<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\ModifierType;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Money\Money;
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
 * Значення опції (ADR Rev 6), один із двох видів:
 *  - modifier: absolute (amountMinor + currency) або percent (basisPoints); компонентних полів нема;
 *  - component: componentVariant × componentQuantity, опційно selectionPriceMinor + currency (ціна за весь вибір,
 *    не за одиницю: лиштва 2,5 шт = 1250 грн — ADR Rev 9); полів націнки нема.
 * Створюється лише через OptionGroup::add*(), тому набір заповнених колонок завжди відповідає виду.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_option_value')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_option_value_code', columns: ['group_id', 'code'])]
#[UniqueEntity(fields: ['group', 'code'], message: 'catalog.option_value.code.unique', errorPath: 'code')]
class OptionValue implements TranslatableInterface
{
    /** @use TranslatableTrait<OptionValueTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'values')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OptionGroup $group;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'catalog.option_value.code.not_blank')]
    private string $code;

    #[ORM\Column(length: 16, enumType: OptionValueKind::class)]
    private OptionValueKind $kind;

    #[ORM\Column(length: 16, nullable: true, enumType: ModifierType::class)]
    private ?ModifierType $modifierType = null;

    #[ORM\Column(nullable: true)]
    private ?int $amountMinor = null;

    #[ORM\Column(nullable: true)]
    private ?int $basisPoints = null;

    /** Компонент не можна видалити, поки він у наборі (RESTRICT): інакше конфігурації мовчки зміняться. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?ProductVariant $componentVariant = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $componentQuantity = null;

    #[ORM\Column(nullable: true)]
    /** Ціна за весь вибір компонента (кількість уже врахована); null — ціна компонента × кількість. */
    private ?int $selectionPriceMinor = null;

    /** Валюта amountMinor або selectionPriceMinor. */
    #[ORM\Column(length: 3, nullable: true, options: ['fixed' => true])]
    private ?string $currency = null;

    #[ORM\Column(name: 'is_default')]
    private bool $default = false;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<string, OptionValueTranslation> */
    #[ORM\OneToMany(targetEntity: OptionValueTranslation::class, mappedBy: 'optionValue', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    private function __construct(OptionGroup $group, string $code, OptionValueKind $kind)
    {
        $this->group = $group;
        $this->code = $code;
        $this->kind = $kind;
        $this->translations = new ArrayCollection();
    }

    /** @internal */
    public static function absoluteModifier(OptionGroup $group, string $code, Money $amount): self
    {
        $value = new self($group, $code, OptionValueKind::Modifier);
        $value->modifierType = ModifierType::Absolute;
        $value->amountMinor = $amount->amount;
        $value->currency = $amount->currency;

        return $value;
    }

    /** @internal */
    public static function percentModifier(OptionGroup $group, string $code, int $basisPoints): self
    {
        $value = new self($group, $code, OptionValueKind::Modifier);
        $value->modifierType = ModifierType::Percent;
        $value->basisPoints = $basisPoints;

        return $value;
    }

    /** @internal */
    public static function component(OptionGroup $group, string $code, ProductVariant $variant, string $quantity, ?Money $selectionPrice): self
    {
        $value = new self($group, $code, OptionValueKind::Component);
        $value->componentVariant = $variant;
        $value->componentQuantity = $quantity;
        $value->selectionPriceMinor = $selectionPrice?->amount;
        $value->currency = $selectionPrice?->currency;

        return $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): OptionGroup
    {
        return $this->group;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function changeCode(string $code): void
    {
        $code = MachineCode::assertValid($code);
        $other = $this->group->findValue($code);
        if (null !== $other && $other !== $this) {
            throw new CatalogInvariantViolation(\sprintf('Значення "%s" вже є в групі "%s"', $code, $this->group->getCode()), 'error.option_group.value_exists', ['value' => $code, 'group' => $this->group->getCode()]);
        }
        $this->code = $code;
    }

    public function __toString(): string
    {
        return $this->displayText(static fn (OptionValueTranslation $t): string => $t->getLabel()) ?? $this->code;
    }

    public function getKind(): OptionValueKind
    {
        return $this->kind;
    }

    public function getModifierType(): ?ModifierType
    {
        return $this->modifierType;
    }

    public function getAmount(): ?Money
    {
        return null === $this->amountMinor || null === $this->currency ? null : Money::of($this->amountMinor, $this->currency);
    }

    public function getBasisPoints(): ?int
    {
        return $this->basisPoints;
    }

    public function getComponentVariant(): ?ProductVariant
    {
        return $this->componentVariant;
    }

    public function getComponentQuantity(): ?string
    {
        return $this->componentQuantity;
    }

    public function getSelectionPrice(): ?Money
    {
        return null === $this->selectionPriceMinor || null === $this->currency ? null : Money::of($this->selectionPriceMinor, $this->currency);
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    /** @internal використовуйте OptionGroup::markDefault() */
    public function setDefault(bool $default): void
    {
        $this->default = $default;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new OptionValueTranslation($this, $locale);
    }
}

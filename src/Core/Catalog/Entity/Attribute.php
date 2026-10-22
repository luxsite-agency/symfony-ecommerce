<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Характеристика (EAV, ADR Settled). Тип незмінний після створення: від нього залежить, яка typed-колонка
 * значення заповнена в ProductAttributeValue. `variantAxis` = вісь варіантів (розмір, колір — ADR Rev 2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_attribute')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_attribute_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'catalog.attribute.code.unique')]
class Attribute implements TranslatableInterface
{
    /** @use TranslatableTrait<AttributeTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'catalog.attribute.code.not_blank')]
    private string $code;

    #[ORM\Column(length: 16, enumType: AttributeType::class)]
    private AttributeType $type;

    /** Одиниця виміру значень (мм, кг, lb, ″) — лише для відображення, без перерахунку (ADR Rev 8). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column]
    private bool $filterable = false;

    #[ORM\Column]
    private bool $variantAxis = false;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, AttributeValue> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: AttributeValue::class, mappedBy: 'attribute', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $values;

    /** @var Collection<string, AttributeTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: AttributeTranslation::class, mappedBy: 'attribute', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct(string $code, AttributeType $type)
    {
        $this->code = MachineCode::assertValid($code);
        $this->type = $type;
        $this->values = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function changeCode(string $code): void
    {
        $this->code = MachineCode::assertValid($code);
    }

    /**
     * Тип незмінний після створення: він визначає typed-колонку значень. Змінити можна лише поки характеристика
     * не збережена й не має значень — це ще «створення» (форма адмінки заповнює тип після конструктора).
     */
    public function changeType(AttributeType $type): void
    {
        if ($type === $this->type) {
            return;
        }
        if (null !== $this->id || !$this->values->isEmpty()) {
            throw new CatalogInvariantViolation('Тип характеристики не можна змінити після створення', 'error.attribute.type_locked');
        }
        if ($this->variantAxis && AttributeType::Select !== $type) {
            throw new CatalogInvariantViolation('Віссю варіантів може бути лише select', 'error.attribute.axis_select_only');
        }
        $this->type = $type;
    }

    public function getType(): AttributeType
    {
        return $this->type;
    }

    public function hasOptions(): bool
    {
        return AttributeType::Select === $this->type || AttributeType::MultiSelect === $this->type;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): void
    {
        $unit = null === $unit ? null : trim($unit);
        $this->unit = '' === $unit ? null : $unit;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function setFilterable(bool $filterable): void
    {
        $this->filterable = $filterable;
    }

    public function isVariantAxis(): bool
    {
        return $this->variantAxis;
    }

    /** Вісь варіантів — лише одиночний вибір: варіант має рівно одне значення осі. */
    public function setVariantAxis(bool $variantAxis): void
    {
        if ($variantAxis && AttributeType::Select !== $this->type) {
            throw new CatalogInvariantViolation(\sprintf('Віссю варіантів може бути лише атрибут типу select, "%s" має тип %s', $this->code, $this->type->value), 'error.attribute.axis_requires_select', ['code' => $this->code, 'type' => $this->type->value]);
        }
        $this->variantAxis = $variantAxis;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function addValue(string $code): AttributeValue
    {
        if (!$this->hasOptions()) {
            throw new CatalogInvariantViolation(\sprintf('Атрибут "%s" типу %s не має варіантів значень', $this->code, $this->type->value), 'error.attribute.no_values', ['code' => $this->code, 'type' => $this->type->value]);
        }
        $code = MachineCode::assertValid($code);
        if (null !== $this->findValue($code)) {
            throw new CatalogInvariantViolation(\sprintf('Значення "%s" вже є в атрибуті "%s"', $code, $this->code), 'error.attribute.value_exists', ['value' => $code, 'attribute' => $this->code]);
        }
        $value = new AttributeValue($this, $code);
        $value->setPosition($this->values->count());
        $this->values->add($value);

        return $value;
    }

    public function removeValue(AttributeValue $value): void
    {
        $this->values->removeElement($value);
    }

    public function findValue(string $code): ?AttributeValue
    {
        return $this->values->findFirst(static fn (int $_, AttributeValue $v): bool => $v->getCode() === $code);
    }

    /** @return Collection<int, AttributeValue> */
    public function getValues(): Collection
    {
        return $this->values;
    }

    public function __toString(): string
    {
        return $this->displayText(static fn (AttributeTranslation $t): string => $t->getName()) ?? $this->code;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new AttributeTranslation($this, $locale);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

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

/** Варіант значення select/multiselect-атрибута («червоний», «42»). Створюється лише через Attribute::addValue(). */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_attribute_value')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_attribute_value_code', columns: ['attribute_id', 'code'])]
#[UniqueEntity(fields: ['attribute', 'code'], message: 'catalog.attribute_value.code.unique', errorPath: 'code')]
class AttributeValue implements TranslatableInterface
{
    /** @use TranslatableTrait<AttributeValueTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'values')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Attribute $attribute;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'catalog.attribute_value.code.not_blank')]
    private string $code;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<string, AttributeValueTranslation> */
    #[ORM\OneToMany(targetEntity: AttributeValueTranslation::class, mappedBy: 'attributeValue', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /** @internal використовуйте Attribute::addValue() */
    public function __construct(Attribute $attribute, string $code)
    {
        $this->attribute = $attribute;
        $this->code = $code;
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function changeCode(string $code): void
    {
        $code = MachineCode::assertValid($code);
        $other = $this->attribute->findValue($code);
        if (null !== $other && $other !== $this) {
            throw new CatalogInvariantViolation(\sprintf('Значення "%s" вже є в атрибуті "%s"', $code, $this->attribute->getCode()), 'error.attribute.value_exists', ['value' => $code, 'attribute' => $this->attribute->getCode()]);
        }
        $this->code = $code;
    }

    public function __toString(): string
    {
        return $this->displayText(static fn (AttributeValueTranslation $t): string => $t->getValue()) ?? $this->code;
    }

    public function getCode(): string
    {
        return $this->code;
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
        return new AttributeValueTranslation($this, $locale);
    }
}

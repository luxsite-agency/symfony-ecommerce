<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Значення характеристики товару (EAV-джерело правди). Заповнена рівно одна typed-колонка за типом атрибута.
 * Це НЕ read-index фільтрів (ADR D3): індекс — окремі таблиці catalog_filter_* без сутностей (ADR Rev 9).
 * Створюється лише через Product::addAttributeOption()/setAttributeNumber()/setAttributeBoolean().
 *
 * Унікальність (product, attribute, option): MySQL не порівнює NULL в UNIQUE, тому для number/boolean (option_id NULL)
 * ключем є generated column option_key = COALESCE(option_id, 0), і БД тримає «одне число/прапорець на атрибут».
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_attribute_value')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_attribute_value', columns: ['product_id', 'attribute_id', 'option_key'])]
#[ORM\Index(name: 'idx_catalog_product_attribute_value_attribute', columns: ['attribute_id'])]
class ProductAttributeValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attributeValues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Attribute $attribute;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?AttributeValue $option = null;

    /**
     * Обчислює БД: COALESCE(option_id, 0). VIRTUAL, а не STORED (як в ADR Rev 9), бо MySQL 8.0 не дає ON DELETE CASCADE
     * на базовій колонці STORED-колонки (помилка 1215); в unique-індексі значення однаково матеріалізоване.
     * З PHP не пишеться; заповнена лише після завантаження з БД — без `generated: ALWAYS`, щоб ORM не робив
     * SELECT після кожного INSERT під час імпорту.
     */
    #[ORM\Column(insertable: false, updatable: false, columnDefinition: 'INT GENERATED ALWAYS AS (COALESCE(option_id, 0)) VIRTUAL NOT NULL')]
    private int $optionKey; // @phpstan-ignore property.onlyRead (заповнює лише Doctrine при гідратації з БД)

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 4, nullable: true)]
    private ?string $numberValue = null;

    #[ORM\Column(nullable: true)]
    private ?bool $booleanValue = null;

    private function __construct(Product $product, Attribute $attribute)
    {
        $this->product = $product;
        $this->attribute = $attribute;
    }

    /** @internal */
    public static function option(Product $product, AttributeValue $option): self
    {
        $value = new self($product, $option->getAttribute());
        $value->option = $option;

        return $value;
    }

    /** @internal */
    public static function number(Product $product, Attribute $attribute, string $number): self
    {
        $value = new self($product, $attribute);
        $value->numberValue = self::normalizeNumber($number);

        return $value;
    }

    private static function normalizeNumber(string $number): string
    {
        $normalized = str_replace(',', '.', trim($number));
        if (!preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не число DECIMAL(16,4): "%s"', $number), 'error.number.invalid_decimal', ['value' => $number]);
        }

        return bcadd($normalized, '0', 4);
    }

    /** @internal заміна числа на місці: DELETE+INSERT в одному flush зачепив би unique (option_key = 0) */
    public function changeNumber(string $number): void
    {
        $this->numberValue = self::normalizeNumber($number);
    }

    /** @internal */
    public function changeBoolean(bool $flag): void
    {
        $this->booleanValue = $flag;
    }

    /** @internal */
    public static function boolean(Product $product, Attribute $attribute, bool $flag): self
    {
        $value = new self($product, $attribute);
        $value->booleanValue = $flag;

        return $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function getOption(): ?AttributeValue
    {
        return $this->option;
    }

    /** id опції або 0 для number/boolean — те, що БД кладе в unique-ключ. */
    public function getOptionKey(): int
    {
        return $this->optionKey ?? $this->option?->getId() ?? 0;
    }

    public function getNumberValue(): ?string
    {
        return $this->numberValue;
    }

    public function getBooleanValue(): ?bool
    {
        return $this->booleanValue;
    }

    public function getType(): AttributeType
    {
        return $this->attribute->getType();
    }
}

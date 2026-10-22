<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Option;

use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\NumberOptionRule;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Shared\Money\Money;

/**
 * Двері 1000,00 грн з опціями (приклад ADR Rev 5/6/9):
 *   leaf (single, обов'язкова): pine (default, 0), oak (+15 %)
 *   hinges (multiple): hidden (+123,00), discount (−5 %)
 *   height (number 1800…2400 мм, крок 10): 0,50 грн за мм понад 2000
 *   trim (single): kit — компонент «лиштва» 2,5 шт × 200,00; set — той самий компонент за 450,00 за весь вибір
 */
final class DoorWithOptions
{
    public Product $door;
    public ProductVariant $variant;
    public Product $trimProduct;
    public ProductVariant $trim;
    public OptionGroup $leaf;
    public OptionGroup $hinges;
    public OptionGroup $height;
    public OptionGroup $trimGroup;

    public function __construct(string $suffix = '')
    {
        $this->door = new Product('DOOR'.$suffix, 'UAH');
        $this->door->translate('uk')->setName('Двері')->setSlug('door'.strtolower($suffix));
        $this->variant = $this->door->getDefaultVariant();
        $this->variant->setPrice(Money::of(100_000, 'UAH'));
        $this->variant->setStock('10');

        $this->trimProduct = new Product('TRIM'.$suffix, 'UAH');
        $this->trimProduct->translate('uk')->setName('Лиштва')->setSlug('trim'.strtolower($suffix));
        $this->trim = $this->trimProduct->getDefaultVariant();
        $this->trim->setPrice(Money::of(20_000, 'UAH'));
        $this->trim->setStock('100');
        $this->trim->setQuantityRules('0.5', '0.5');

        $this->leaf = $this->door->addOptionGroup('leaf', OptionSelection::Single, true);
        $this->leaf->translate('uk')->setName('Полотно');
        $pine = $this->leaf->addAbsoluteModifier('pine', Money::of(0, 'UAH'));
        $pine->translate('uk')->setLabel('Сосна');
        $this->leaf->markDefault($pine);
        $this->leaf->addPercentModifier('oak', 1500)->translate('uk')->setLabel('Дуб');

        $this->hinges = $this->door->addOptionGroup('hinges', OptionSelection::Multiple);
        $this->hinges->addAbsoluteModifier('hidden', Money::of(12_300, 'UAH'));
        $this->hinges->addPercentModifier('discount', -500);

        $this->height = $this->door->addOptionGroup('height');
        $this->height->translate('uk')->setName('Висота');
        $this->height->configureNumber('1800', '2400', '10', NumberOptionRule::AboveThreshold, Money::of(50, 'UAH'), 'мм', '2000');

        $this->trimGroup = $this->door->addOptionGroup('trim');
        $this->trimGroup->addComponent('kit', $this->trim, '2.5');
        $this->trimGroup->addComponent('set', $this->trim, '2.5', Money::of(45_000, 'UAH'));
    }
}

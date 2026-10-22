<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Осі варіанта (ADR Rev 2): по селекту на кожну характеристику з variantAxis. Значення пише
 * ProductVariant::setAxisValue() (одне на вісь, унікальна комбінація в товарі), порожнє — removeAxisValue().
 *
 * @extends AbstractType<ProductVariant>
 */
final class VariantAxesType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Attribute> $axes */
        $axes = $this->em->getRepository(Attribute::class)->findBy(['variantAxis' => true], ['position' => 'ASC', 'code' => 'ASC']);
        foreach ($axes as $attribute) {
            $builder->add('axis_'.$attribute->getCode(), ChoiceType::class, [
                'label' => (string) $attribute,
                // підпис і значення — дані характеристики, не ключі перекладу
                'translation_domain' => false,
                'required' => false,
                'placeholder' => '—',
                'choices' => $attribute->getValues()->toArray(),
                'choice_label' => static fn (AttributeValue $v): string => (string) $v,
                'choice_value' => static fn (?AttributeValue $v): string => null === $v ? '' : (string) $v->getId(),
                'getter' => static fn (ProductVariant $variant): ?AttributeValue => $variant->getAxisValue($attribute),
                'setter' => static function (ProductVariant $variant, ?AttributeValue $value) use ($attribute): void {
                    if (null === $value) {
                        $variant->removeAxisValue($attribute);
                    } else {
                        $variant->setAxisValue($value);
                    }
                },
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true, 'label' => false]);
    }
}

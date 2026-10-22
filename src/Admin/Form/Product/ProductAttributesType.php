<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductAttributeValue;
use App\Core\Catalog\Enum\AttributeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Характеристики картки (EAV): по полю на кожну характеристику, крім осей варіантів (вони — у варіантах).
 * Пише через Product::addAttributeOption()/setAttributeNumber()/setAttributeBoolean(); порожнє → removeAttribute().
 * Підписи й значення — дані характеристик (вже мовою адмінки), тому translation_domain=false; «Так/Ні» — ключі домену admin.
 *
 * @extends AbstractType<Product>
 */
final class ProductAttributesType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Attribute> $attributes */
        $attributes = $this->em->getRepository(Attribute::class)->findBy(['variantAxis' => false], ['position' => 'ASC', 'code' => 'ASC']);
        foreach ($attributes as $attribute) {
            $name = 'attr_'.$attribute->getCode();
            $label = (string) $attribute.(null !== $attribute->getUnit() ? ', '.$attribute->getUnit() : '');
            match ($attribute->getType()) {
                AttributeType::Select => $builder->add($name, ChoiceType::class, [
                    'label' => $label,
                    'translation_domain' => false,
                    'required' => false,
                    'placeholder' => '—',
                    'choices' => $attribute->getValues()->toArray(),
                    'choice_label' => static fn (AttributeValue $v): string => (string) $v,
                    'choice_value' => static fn (?AttributeValue $v): string => null === $v ? '' : (string) $v->getId(),
                    'getter' => static fn (Product $p): ?AttributeValue => self::options($p, $attribute)[0] ?? null,
                    'setter' => static function (Product $p, ?AttributeValue $v) use ($attribute): void {
                        null === $v ? $p->removeAttribute($attribute) : $p->addAttributeOption($v);
                    },
                ]),
                AttributeType::MultiSelect => $builder->add($name, ChoiceType::class, [
                    'label' => $label,
                    'translation_domain' => false,
                    'required' => false,
                    'multiple' => true,
                    'expanded' => true,
                    'choices' => $attribute->getValues()->toArray(),
                    'choice_label' => static fn (AttributeValue $v): string => (string) $v,
                    'choice_value' => static fn (?AttributeValue $v): string => null === $v ? '' : (string) $v->getId(),
                    'getter' => static fn (Product $p): array => self::options($p, $attribute),
                    'setter' => static function (Product $p, array $values) use ($attribute): void {
                        $p->removeAttribute($attribute);
                        foreach ($values as $v) {
                            $p->addAttributeOption($v);
                        }
                    },
                ]),
                AttributeType::Number => $builder->add($name, TextType::class, [
                    'label' => $label,
                    'translation_domain' => false,
                    'required' => false,
                    'getter' => static fn (Product $p): ?string => ($p->getAttributeValues($attribute)[0] ?? null)?->getNumberValue(),
                    'setter' => static function (Product $p, ?string $v) use ($attribute): void {
                        '' === trim((string) $v) ? $p->removeAttribute($attribute) : $p->setAttributeNumber($attribute, (string) $v);
                    },
                ]),
                AttributeType::Boolean => $builder->add($name, ChoiceType::class, [
                    'label' => $label,
                    'translation_domain' => false,
                    'choice_translation_domain' => 'admin',
                    'required' => false,
                    'placeholder' => '—',
                    'choices' => ['catalog_form.boolean.yes' => 'yes', 'catalog_form.boolean.no' => 'no'],
                    'getter' => static function (Product $p) use ($attribute): ?string {
                        $flag = ($p->getAttributeValues($attribute)[0] ?? null)?->getBooleanValue();

                        return null === $flag ? null : ($flag ? 'yes' : 'no');
                    },
                    'setter' => static function (Product $p, ?string $v) use ($attribute): void {
                        null === $v || '' === $v ? $p->removeAttribute($attribute) : $p->setAttributeBoolean($attribute, 'yes' === $v);
                    },
                ]),
            };
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true, 'label' => false]);
    }

    /** @return list<AttributeValue> */
    private static function options(Product $product, Attribute $attribute): array
    {
        return array_values(array_filter(array_map(
            static fn (ProductAttributeValue $v): ?AttributeValue => $v->getOption(),
            $product->getAttributeValues($attribute),
        )));
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Form\Translation\SingleFieldTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\AttributeValueTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Значення select/multiselect-характеристики в колекції форми Attribute. Новий рядок створює Attribute::addValue().
 *
 * @extends AbstractType<AttributeValue>
 */
final class AttributeValueType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'catalog_form.field.code',
                'help' => 'catalog_form.help.attribute_value_code',
                'setter' => static fn (AttributeValue $value, ?string $code) => $value->changeCode((string) $code),
            ])
            ->add('position', IntegerType::class, ['label' => 'catalog_form.field.position', 'empty_data' => '0'])
            ->add('translationsForm', TranslationsType::class, [
                'entry_type' => SingleFieldTranslationType::class,
                'entry_options' => ['data_class' => AttributeValueTranslation::class, 'field' => 'value', 'field_label' => 'catalog_form.field.value'],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
                static fn (Attribute $attribute, array $raw): AttributeValue => $attribute->addValue(trim((string) ($raw['code'] ?? ''))),
                'code',
                $this->translator,
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Без empty_data: об'єкт створює PRE_SUBMIT; якщо не вдалося — null, і дочірні поля нікуди не мапляться.
        $resolver->setDefaults(['data_class' => AttributeValue::class, 'empty_data' => null, 'translation_domain' => 'admin']);
    }
}

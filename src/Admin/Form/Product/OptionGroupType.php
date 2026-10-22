<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Admin\Form\CollectionSync;
use App\Admin\Form\Translation\SingleFieldTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\OptionGroupTranslation;
use App\Core\Catalog\Entity\OptionValue;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\OptionSelection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Група опцій товару (ADR Rev 6) з колекцією значень. Нову групу створює Product::addOptionGroup() у PRE_SUBMIT.
 *
 * @extends AbstractType<OptionGroup>
 */
final class OptionGroupType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['label' => 'catalog_form.field.code', 'help' => 'catalog_form.help.option_group_code', 'setter' => static fn (OptionGroup $g, ?string $code) => $g->changeCode((string) $code)])
            ->add('selection', EnumType::class, ['label' => 'catalog_form.field.selection', 'class' => OptionSelection::class, 'choice_label' => static fn (OptionSelection $s): string => OptionSelection::Single === $s ? 'catalog_form.selection.single' : 'catalog_form.selection.multiple'])
            ->add('required', CheckboxType::class, ['label' => 'catalog_form.field.required', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'catalog_form.field.position', 'empty_data' => '0'])
            ->add('translationsForm', TranslationsType::class, [
                'entry_type' => SingleFieldTranslationType::class,
                'entry_options' => ['data_class' => OptionGroupTranslation::class],
            ])
            ->add('values', CollectionType::class, [
                'label' => 'catalog_form.field.values',
                'entry_type' => OptionValueType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype_name' => '__value__',
                'setter' => CollectionSync::setter(
                    static fn (OptionGroup $g) => $g->getValues(),
                    static fn (OptionGroup $g, object $v) => $v instanceof OptionValue ? $g->removeValue($v) : null,
                ),
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
            static fn (Product $product, array $raw): OptionGroup => $product->addOptionGroup(
                trim((string) ($raw['code'] ?? '')),
                OptionSelection::tryFrom((string) ($raw['selection'] ?? '')) ?? OptionSelection::Single,
                (bool) ($raw['required'] ?? false),
            ),
            'code',
            $this->translator,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OptionGroup::class, 'empty_data' => null, 'translation_domain' => 'admin']);
    }
}

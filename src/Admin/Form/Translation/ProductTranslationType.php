<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Catalog\Entity\ProductTranslation;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'catalog_form.translation.name', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'catalog_form.error.product_name_required'))
            ->add('slug', TextType::class, ['label' => 'catalog_form.translation.slug', 'required' => false, 'help' => 'catalog_form.translation.help.slug_from_name'])
            ->add('shortDescription', TextareaType::class, ['label' => 'catalog_form.translation.short_description', 'required' => false, 'attr' => ['rows' => 3]] + self::HTML)
            ->add('description', TextareaType::class, ['label' => 'catalog_form.translation.description', 'required' => false, 'attr' => ['rows' => 8]] + self::HTML)
            ->add('metaTitle', TextType::class, ['label' => 'catalog_form.translation.meta_title', 'required' => false])
            ->add('metaDescription', TextareaType::class, ['label' => 'catalog_form.translation.meta_description', 'required' => false, 'attr' => ['rows' => 2]]);
        $this->addSlugFromName($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => ProductTranslation::class]);
    }
}

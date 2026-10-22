<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Catalog\Entity\CategoryTranslation;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CategoryTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'catalog_form.translation.name', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'catalog_form.error.category_name_required'))
            ->add('slug', TextType::class, ['label' => 'catalog_form.translation.slug', 'required' => false, 'help' => 'catalog_form.translation.help.slug_from_name'])
            ->add('description', TextareaType::class, ['label' => 'catalog_form.translation.description', 'required' => false, 'attr' => ['rows' => 5]] + self::HTML)
            ->add('h1', TextType::class, ['label' => 'catalog_form.translation.h1', 'required' => false, 'help' => 'catalog_form.translation.help.h1'])
            ->add('metaTitle', TextType::class, ['label' => 'catalog_form.translation.meta_title', 'required' => false])
            ->add('metaDescription', TextareaType::class, ['label' => 'catalog_form.translation.meta_description', 'required' => false, 'attr' => ['rows' => 2]]);
        $this->addSlugFromName($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => CategoryTranslation::class]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Переклад з назвою і слагом (рубрика блогу). */
final class NameSlugTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'catalog_form.translation.name', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'catalog_form.error.name_required'))
            ->add('slug', TextType::class, ['label' => 'catalog_form.translation.slug', 'required' => false, 'help' => 'catalog_form.translation.help.slug_from_name']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setRequired('data_class');
    }
}

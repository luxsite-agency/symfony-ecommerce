<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Переклад з одним полем (name/value/label): характеристика, її значення, група опцій, значення опції. */
final class SingleFieldTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add($options['field'], TextType::class, ['label' => $options['field_label'], 'empty_data' => ''] + $this->requiredIf($options['required_locale']));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        // field_label — ключ домену admin
        $resolver->setDefaults(['field' => 'name', 'field_label' => 'catalog_form.translation.name']);
        $resolver->setRequired('data_class');
        $resolver->setAllowedTypes('field', 'string');
    }
}

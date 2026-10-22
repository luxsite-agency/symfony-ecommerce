<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ProfileData> */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales = $options['locales'];
        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('phone', TelType::class, ['required' => false])
            ->add('locale', ChoiceType::class, ['choices' => array_combine($locales, $locales)])
            ->add('marketing', CheckboxType::class, ['required' => false, 'false_values' => [null, '', '0']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileData::class, 'translation_domain' => 'messages', 'locales' => []]);
        $resolver->setAllowedTypes('locales', 'array');
    }
}

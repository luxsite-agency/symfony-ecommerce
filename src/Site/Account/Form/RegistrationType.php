<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<RegistrationData> */
final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['empty_data' => ''])
            ->add('consent', CheckboxType::class, ['required' => false, 'false_values' => [null, '', '0']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // CSRF — stateless (token_id submit, csrf.yaml): сторінка реєстрації сесію не стартує
        $resolver->setDefaults(['data_class' => RegistrationData::class, 'translation_domain' => 'messages']);
    }
}

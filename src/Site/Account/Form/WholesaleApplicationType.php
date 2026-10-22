<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Форма «Стати оптовим клієнтом» (E13.1): CSRF stateless (token_id submit, csrf.yaml), підписи в шаблоні.
 *
 * @extends AbstractType<WholesaleApplicationData>
 */
final class WholesaleApplicationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', TextType::class)
            ->add('taxId', TextType::class)
            ->add('vatPayer', CheckboxType::class, ['required' => false])
            ->add('contactName', TextType::class)
            ->add('contactPhone', TelType::class, ['required' => false])
            ->add('note', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WholesaleApplicationData::class]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Content\Entity\SolutionGroupTranslation;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SolutionGroupTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, ['label' => 'solution_form.group_title', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'solution_form.error.group_title_required'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SolutionGroupTranslation::class]);
    }
}

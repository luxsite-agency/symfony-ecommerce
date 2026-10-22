<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Content\Entity\SolutionOptionTranslation;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Переклад варіанта рішення: переваги рядками й абзац. */
final class SolutionOptionTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prosText', TextareaType::class, ['label' => 'solution_form.pros', 'required' => false, 'help' => 'solution_form.help.lines', 'attr' => ['rows' => 4]])
            ->add('note', TextareaType::class, ['label' => 'solution_form.note', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SolutionOptionTranslation::class]);
    }
}

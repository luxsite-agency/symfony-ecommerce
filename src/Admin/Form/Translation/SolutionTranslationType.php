<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Content\Entity\SolutionTranslation;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Переклад рішення: тексти, списки «Вантажі»/«Галузі» рядками, FAQ блоками, SEO. Підписи — ключі домену admin. */
final class SolutionTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'solution_form.title', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'solution_form.error.title_required'))
            ->add('slug', TextType::class, ['label' => 'solution_form.slug', 'required' => false, 'help' => 'solution_form.help.slug'])
            ->add('intro', TextareaType::class, ['label' => 'solution_form.intro', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('task', TextareaType::class, ['label' => 'solution_form.task', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('loadsText', TextareaType::class, ['label' => 'solution_form.loads', 'required' => false, 'help' => 'solution_form.help.lines', 'attr' => ['rows' => 4]])
            ->add('industriesText', TextareaType::class, ['label' => 'solution_form.industries', 'required' => false, 'help' => 'solution_form.help.lines', 'attr' => ['rows' => 4]])
            ->add('body', TextEditorType::class, ['label' => 'solution_form.body', 'required' => false, 'help' => 'solution_form.help.body'] + self::HTML)
            ->add('faqText', TextareaType::class, ['label' => 'solution_form.faq', 'required' => false, 'help' => 'solution_form.help.faq', 'attr' => ['rows' => 6]])
            ->add('metaTitle', TextType::class, ['label' => 'solution_form.meta_title', 'required' => false])
            ->add('metaDescription', TextareaType::class, ['label' => 'solution_form.meta_description', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SolutionTranslation::class]);
    }
}

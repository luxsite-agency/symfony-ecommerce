<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Content\Entity\PageTranslation;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PageTranslationType extends AbstractTranslationType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'catalog_form.translation.title', 'empty_data' => ''] + $this->requiredIf($options['required_locale'], 'catalog_form.error.page_title_required'))
            ->add('slug', TextType::class, ['label' => 'catalog_form.translation.slug', 'required' => false, 'help' => 'catalog_form.translation.help.slug_from_title'])
            ->add('body', TextEditorType::class, ['label' => 'catalog_form.translation.body', 'required' => false] + self::HTML)
            ->add('metaTitle', TextType::class, ['label' => 'catalog_form.translation.meta_title', 'required' => false])
            ->add('metaDescription', TextareaType::class, ['label' => 'catalog_form.translation.meta_description', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => PageTranslation::class]);
    }
}

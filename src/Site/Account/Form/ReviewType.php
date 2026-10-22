<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Catalog\Entity\ProductReview;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ReviewData> */
final class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $ratings = range(ProductReview::RATING_MAX, ProductReview::RATING_MIN);
        $builder
            ->add('product', IntegerType::class, ['empty_data' => '0'])
            ->add('rating', ChoiceType::class, ['choices' => array_combine($ratings, $ratings), 'expanded' => true, 'required' => true, 'placeholder' => false])
            ->add('text', TextareaType::class, ['empty_data' => '']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReviewData::class, 'translation_domain' => 'messages']);
    }
}

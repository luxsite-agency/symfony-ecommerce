<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Order\Enum\ShippingMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AddressData> */
final class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $methods = [];
        foreach (ShippingMethod::selectable() as $method) {
            $methods[$method->value] = $method->value;
        }
        $builder
            ->add('label', TextType::class, ['required' => false])
            ->add('recipientName', TextType::class, ['empty_data' => ''])
            ->add('recipientPhone', TelType::class, ['empty_data' => ''])
            ->add('shipping', ChoiceType::class, ['choices' => $methods, 'empty_data' => ShippingMethod::NovaPoshta->value])
            ->add('city', TextType::class, ['required' => false])
            ->add('address', TextType::class, ['required' => false])
            ->add('npCityRef', HiddenType::class, ['empty_data' => ''])
            ->add('npWarehouseRef', HiddenType::class, ['empty_data' => ''])
            ->add('isDefault', CheckboxType::class, ['required' => false, 'false_values' => [null, '', '0']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // без префікса імен (name="city", не address[city]): JS-пікер НП шукає ті самі імена, що на checkout
        $resolver->setDefaults(['data_class' => AddressData::class, 'translation_domain' => 'messages']);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}

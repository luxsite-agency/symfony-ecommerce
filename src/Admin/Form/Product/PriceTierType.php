<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Admin\Form\CollectionSync;
use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\CustomerGroup;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Поріг ціни (ADR Rev 7) усередині варіанта. PriceTier незмінний: новий рядок створює
 * ProductVariant::addPriceTier() у PRE_SUBMIT, існуючий показується без редагування (змінити = видалити й додати).
 *
 * @extends AbstractType<PriceTier>
 */
final class PriceTierType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Усі поля існуючого порога disabled, а браузер disabled не надсилає — без цього маркера рядок зникав би
            // з відправленої колекції і поріг видалявся б при кожному збереженні.
            ->add('keep', HiddenType::class, ['mapped' => false, 'data' => '1'])
            ->add('minQuantity', TextType::class, ['label' => 'catalog_form.field.tier_min_quantity', 'mapped' => false, 'attr' => ['placeholder' => '10']])
            ->add('price', TextType::class, ['label' => 'catalog_form.field.price', 'mapped' => false, 'attr' => ['placeholder' => '0.00']])
            ->add('customerGroup', EntityType::class, [
                'label' => 'catalog_form.field.customer_group',
                'class' => CustomerGroup::class,
                'mapped' => false,
                'required' => false,
                'placeholder' => 'catalog_form.placeholder.all_customers',
                'choice_label' => 'name',
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $tier = $event->getData();
            if (!$tier instanceof PriceTier) {
                return;
            }
            $form = $event->getForm();
            foreach (['minQuantity' => $tier->getMinQuantity(), 'price' => MoneyInput::format($tier->getPrice()), 'customerGroup' => $tier->getCustomerGroup()] as $name => $value) {
                $config = $form->get($name)->getConfig();
                $form->add($name, $config->getType()->getInnerType()::class, ['disabled' => true, 'data' => $value] + array_diff_key($config->getOptions(), ['data' => 1, 'disabled' => 1]));
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
            function (ProductVariant $variant, array $raw): PriceTier {
                $group = isset($raw['customerGroup']) && '' !== $raw['customerGroup'] ? $this->em->find(CustomerGroup::class, (int) $raw['customerGroup']) : null;
                $price = MoneyInput::parse($raw['price'] ?? '', $variant->getCurrency()) ?? throw new InvalidArgumentException($this->translator->trans('catalog_form.error.tier_price_required', [], 'admin'));

                return $variant->addPriceTier(trim((string) ($raw['minQuantity'] ?? '')), $price, $group);
            },
            'price',
            $this->translator,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PriceTier::class, 'empty_data' => null, 'label' => false, 'translation_domain' => 'admin']);
    }
}

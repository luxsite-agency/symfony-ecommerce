<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Admin\Form\CollectionSync;
use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use InvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Варіант у колекції форми товару. Новий створює Product::addVariant() у PRE_SUBMIT; ціна й перекреслена ціна
 * задаються парою (setPrice), кількісні правила — парою (setQuantityRules), тому ці поля unmapped + POST_SUBMIT.
 *
 * @extends AbstractType<ProductVariant>
 */
final class ProductVariantType extends AbstractType
{
    /** SKU-заглушка нового товару (Product вимагає перший варіант у конструкторі); у формі показується порожнім. */
    public const string DRAFT_SKU_PREFIX = 'DRAFT-';

    // Ключі перекладу (домен admin) для enum-ів ядра: catalog_form.stock_policy.<value>, catalog_form.unit.<value>.
    private const string STOCK_POLICY_LABEL_PREFIX = 'catalog_form.stock_policy.';
    public const string UNIT_LABEL_PREFIX = 'catalog_form.unit.';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sku', TextType::class, ['label' => 'catalog_form.field.sku', 'setter' => static fn (ProductVariant $v, ?string $sku) => $v->changeSku((string) $sku)])
            ->add('externalId', TextType::class, ['label' => 'catalog_form.field.external_id', 'required' => false])
            ->add('barcode', TextType::class, ['label' => 'catalog_form.field.barcode', 'required' => false])
            ->add('price', TextType::class, ['label' => 'catalog_form.field.price', 'mapped' => false, 'attr' => ['placeholder' => '0.00']])
            ->add('oldPrice', TextType::class, ['label' => 'catalog_form.field.old_price', 'mapped' => false, 'required' => false])
            ->add('stock', TextType::class, ['label' => 'catalog_form.field.stock', 'setter' => static fn (ProductVariant $v, ?string $stock) => $v->setStock('' === trim((string) $stock) ? '0' : (string) $stock)])
            ->add('stockPolicy', EnumType::class, ['label' => 'catalog_form.field.stock_policy', 'class' => StockPolicy::class, 'choice_label' => static fn (StockPolicy $p): string => self::STOCK_POLICY_LABEL_PREFIX.$p->value])
            ->add('leadTimeDays', IntegerType::class, ['label' => 'catalog_form.field.lead_time_days', 'required' => false])
            ->add('unit', EnumType::class, ['label' => 'catalog_form.field.sales_unit', 'class' => SalesUnit::class, 'choice_label' => static fn (SalesUnit $u): string => self::UNIT_LABEL_PREFIX.$u->value])
            ->add('minQuantity', TextType::class, ['label' => 'catalog_form.field.min_quantity', 'mapped' => false])
            ->add('quantityStep', TextType::class, ['label' => 'catalog_form.field.quantity_step', 'mapped' => false])
            ->add('packQuantity', TextType::class, ['label' => 'catalog_form.field.pack_quantity', 'required' => false, 'setter' => static fn (ProductVariant $v, ?string $q) => $v->setPackQuantity('' === trim((string) $q) ? null : $q)])
            ->add('position', IntegerType::class, ['label' => 'catalog_form.field.position', 'empty_data' => '0'])
            ->add('active', CheckboxType::class, ['label' => 'catalog_form.field.active', 'required' => false])
            ->add('axes', VariantAxesType::class)
            ->add('priceTiers', CollectionType::class, [
                'label' => 'catalog_form.field.price_tiers',
                'entry_type' => PriceTierType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype_name' => '__tier__',
                'setter' => CollectionSync::setter(
                    static fn (ProductVariant $v) => $v->getPriceTiers(),
                    static fn (ProductVariant $v, object $tier) => $tier instanceof PriceTier ? $v->removePriceTier($tier) : null,
                ),
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
            static fn (Product $product, array $raw): ProductVariant => $product->addVariant(trim((string) ($raw['sku'] ?? ''))),
            'sku',
            $this->translator,
        ));

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $variant = $event->getData();
            if (!$variant instanceof ProductVariant) {
                return;
            }
            $form = $event->getForm();
            $form->get('price')->setData(MoneyInput::format($variant->getPrice()));
            $form->get('oldPrice')->setData(MoneyInput::format($variant->getOldPrice()));
            $form->get('minQuantity')->setData($variant->getMinQuantity());
            $form->get('quantityStep')->setData($variant->getQuantityStep());
            if (null === $variant->getId() && str_starts_with($variant->getSku(), self::DRAFT_SKU_PREFIX)) {
                $form->get('sku')->setData('');
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $variant = $event->getData();
            if (!$variant instanceof ProductVariant) {
                return;
            }
            $form = $event->getForm();
            try {
                $price = MoneyInput::parse($form->get('price')->getData(), $variant->getCurrency()) ?? throw new InvalidArgumentException($this->translator->trans('catalog_form.error.price_required', [], 'admin'));
                $variant->setPrice($price, MoneyInput::parse($form->get('oldPrice')->getData(), $variant->getCurrency()));
            } catch (InvalidArgumentException|CatalogInvariantViolation $e) {
                $form->get('price')->addError(new FormError(CollectionSync::errorMessage($e, $this->translator), null, [], null, $e));
            }
            try {
                $variant->setQuantityRules((string) $form->get('minQuantity')->getData(), (string) $form->get('quantityStep')->getData());
            } catch (InvalidArgumentException $e) {
                $form->get('minQuantity')->addError(new FormError(CollectionSync::errorMessage($e, $this->translator), null, [], null, $e));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductVariant::class, 'empty_data' => null, 'translation_domain' => 'admin']);
    }
}

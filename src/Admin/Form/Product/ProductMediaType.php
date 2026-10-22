<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Admin\Form\CollectionSync;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductMedia;
use App\Core\Catalog\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Файл галереї товару: вибір з медіатеки (завантаження — у «Медіатеці»), опційно прив'язка до варіанта, порядок.
 * Новий рядок створює Product::addMedia() у PRE_SUBMIT; файл рядка не змінюється (видалити й додати).
 *
 * @extends AbstractType<ProductMedia>
 */
final class ProductMediaType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('media', EntityType::class, [
                'label' => 'catalog_form.field.media',
                'mapped' => false,
                'class' => Media::class,
                'choice_label' => static fn (Media $m): string => (string) $m,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC'),
                'placeholder' => '—',
            ])
            ->add('variant', ChoiceType::class, ['label' => 'catalog_form.field.media_variant', 'required' => false, 'placeholder' => 'catalog_form.placeholder.whole_product', 'choices' => []])
            ->add('position', IntegerType::class, ['label' => 'catalog_form.field.position', 'empty_data' => '0']);

        // Варіанти беруться з товару в пам'яті (у нового товару їх ще нема в БД), тому селект будується на PRE_SET_DATA.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $form = $event->getForm();
            $product = $form->getParent()?->getParent()?->getData();
            $variants = $product instanceof Product ? $product->getVariants()->toArray() : [];
            $form->add('variant', ChoiceType::class, [
                'label' => 'catalog_form.field.media_variant',
                'required' => false,
                'placeholder' => 'catalog_form.placeholder.whole_product',
                'choices' => $variants,
                'choice_translation_domain' => false,
                'choice_label' => static fn (ProductVariant $v): string => $v->getSku(),
                'choice_value' => static fn (?ProductVariant $v): string => $v?->getSku() ?? '',
            ]);
        });

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $link = $event->getData();
            if (!$link instanceof ProductMedia) {
                return;
            }
            $form = $event->getForm();
            $config = $form->get('media')->getConfig();
            $form->add('media', EntityType::class, ['disabled' => true, 'data' => $link->getMedia()] + array_diff_key($config->getOptions(), ['data' => 1, 'disabled' => 1]));
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
            function (Product $product, array $raw): ProductMedia {
                $media = '' !== ($raw['media'] ?? '') ? $this->em->find(Media::class, (int) $raw['media']) : null;
                if (!$media instanceof Media) {
                    throw new InvalidArgumentException($this->translator->trans('catalog_form.error.media_required', [], 'admin'));
                }

                return $product->addMedia($media);
            },
            'media',
            $this->translator,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductMedia::class, 'empty_data' => null, 'translation_domain' => 'admin']);
    }
}

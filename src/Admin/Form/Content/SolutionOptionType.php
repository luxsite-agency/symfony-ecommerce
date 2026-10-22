<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Content;

use App\Admin\Form\Translation\SolutionOptionTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\SolutionOption;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\CrudAutocompleteType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Варіант рішення в колекції форми рішення: товар (автокомпліт EasyAdmin з пошуком на сервері — SolutionProductLookupController,
 * у select лише обраний товар, а не весь каталог), порядок і переклади переваг/абзацу.
 *
 * @extends AbstractType<SolutionOption>
 */
final class SolutionOptionType extends AbstractType
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', CrudAutocompleteType::class, [
                'label' => 'solution_form.product',
                'class' => Product::class,
                'attr' => [
                    'data-ea-widget' => 'ea-autocomplete',
                    // EasyAdmin дописує &query=…, тому адреса вже з «?»
                    'data-ea-autocomplete-endpoint-url' => $this->urls->generate('admin_solution_products', ['page' => 1]),
                ],
            ])
            ->add('position', IntegerType::class, ['label' => 'solution_form.position', 'empty_data' => '0'])
            ->add('translationsForm', TranslationsType::class, ['label' => false, 'entry_type' => SolutionOptionTranslationType::class]);
        // новий рядок колекції: варіант потрібен до сабміту дочірніх полів — інакше переклади (inherit_data) не мають куди писати
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            if (null === $event->getForm()->getData()) {
                $event->getForm()->setData(new SolutionOption());
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SolutionOption::class]);
    }
}

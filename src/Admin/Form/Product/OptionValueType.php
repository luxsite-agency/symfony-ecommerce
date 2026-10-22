<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Admin\Form\CollectionSync;
use App\Admin\Form\Translation\SingleFieldTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\OptionValue;
use App\Core\Catalog\Entity\OptionValueTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Значення опції (ADR Rev 6): modifier (сума або відсоток) чи component (варіант × кількість). Вид і його дані
 * незмінні — новий рядок створює фабрика групи у PRE_SUBMIT, існуючий показує їх disabled; змінюються код,
 * «за замовчуванням», порядок і переклади.
 *
 * @extends AbstractType<OptionValue>
 */
final class OptionValueType extends AbstractType
{
    private const array KIND_FIELDS = ['kind', 'modifierType', 'amount', 'percent', 'componentVariant', 'componentQuantity', 'selectionPrice'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['label' => 'catalog_form.field.code', 'setter' => static fn (OptionValue $v, ?string $code) => $v->changeCode((string) $code)])
            ->add('kind', ChoiceType::class, ['label' => 'catalog_form.field.value_kind', 'mapped' => false, 'choices' => ['catalog_form.value_kind.modifier' => 'modifier', 'catalog_form.value_kind.component' => 'component'], 'data' => 'modifier'])
            ->add('modifierType', ChoiceType::class, ['label' => 'catalog_form.field.modifier_type', 'mapped' => false, 'required' => false, 'choices' => ['catalog_form.modifier_type.absolute' => 'absolute', 'catalog_form.modifier_type.percent' => 'percent'], 'data' => 'absolute'])
            ->add('amount', TextType::class, ['label' => 'catalog_form.field.amount', 'mapped' => false, 'required' => false])
            ->add('percent', TextType::class, ['label' => 'catalog_form.field.percent', 'mapped' => false, 'required' => false, 'help' => 'catalog_form.help.percent'])
            ->add('componentVariant', EntityType::class, [
                'label' => 'catalog_form.field.component_variant',
                'mapped' => false,
                'required' => false,
                'class' => ProductVariant::class,
                'choice_label' => static fn (ProductVariant $v): string => \sprintf('%s — %s', $v->getSku(), (string) $v->getProduct()),
                'choice_translation_domain' => false,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('v')->orderBy('v.sku', 'ASC'),
                'placeholder' => '—',
            ])
            ->add('componentQuantity', TextType::class, ['label' => 'catalog_form.field.component_quantity', 'mapped' => false, 'required' => false])
            ->add('selectionPrice', TextType::class, ['label' => 'catalog_form.field.selection_price', 'mapped' => false, 'required' => false, 'help' => 'catalog_form.help.selection_price'])
            ->add('default', CheckboxType::class, [
                'label' => 'catalog_form.field.default',
                'required' => false,
                'getter' => static fn (OptionValue $v): bool => $v->isDefault(),
                'setter' => static fn (OptionValue $v, ?bool $flag) => $v->getGroup()->markDefault($v, (bool) $flag),
            ])
            ->add('position', IntegerType::class, ['label' => 'catalog_form.field.position', 'empty_data' => '0'])
            ->add('translationsForm', TranslationsType::class, [
                'entry_type' => SingleFieldTranslationType::class,
                'entry_options' => ['data_class' => OptionValueTranslation::class, 'field' => 'label', 'field_label' => 'catalog_form.field.label'],
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, CollectionSync::preSubmitFactory(
            function (OptionGroup $group, array $raw): OptionValue {
                $code = trim((string) ($raw['code'] ?? ''));
                $currency = $group->getProduct()->getCurrency();
                if ('component' === ($raw['kind'] ?? 'modifier')) {
                    $variant = '' !== ($raw['componentVariant'] ?? '') ? $this->em->find(ProductVariant::class, (int) $raw['componentVariant']) : null;
                    if (!$variant instanceof ProductVariant) {
                        throw new InvalidArgumentException($this->translator->trans('catalog_form.error.component_variant_required', [], 'admin'));
                    }

                    return $group->addComponent($code, $variant, trim((string) ($raw['componentQuantity'] ?? '')), MoneyInput::parse($raw['selectionPrice'] ?? '', $currency));
                }
                if ('percent' === ($raw['modifierType'] ?? 'absolute')) {
                    $percent = str_replace([',', ' '], ['.', ''], trim((string) ($raw['percent'] ?? '')));
                    if (!is_numeric($percent)) {
                        throw new InvalidArgumentException($this->translator->trans('catalog_form.error.percent_not_number', [], 'admin'));
                    }

                    return $group->addPercentModifier($code, (int) bcmul($percent, '100', 0));
                }

                return $group->addAbsoluteModifier($code, MoneyInput::parse($raw['amount'] ?? '', $currency) ?? throw new InvalidArgumentException($this->translator->trans('catalog_form.error.amount_required', [], 'admin')));
            },
            'code',
            $this->translator,
        ));

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $value = $event->getData();
            if (!$value instanceof OptionValue) {
                return;
            }
            $form = $event->getForm();
            $data = [
                'kind' => $value->getKind()->value,
                'modifierType' => $value->getModifierType()?->value,
                'amount' => MoneyInput::format($value->getAmount()),
                'percent' => null === $value->getBasisPoints() ? null : bcdiv((string) $value->getBasisPoints(), '100', 2),
                'componentVariant' => $value->getComponentVariant(),
                'componentQuantity' => $value->getComponentQuantity(),
                'selectionPrice' => MoneyInput::format($value->getSelectionPrice()),
            ];
            foreach (self::KIND_FIELDS as $name) {
                self::freeze($form, $name, $data[$name]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OptionValue::class, 'empty_data' => null, 'translation_domain' => 'admin']);
    }

    /** @param FormInterface<mixed> $form */
    private static function freeze(FormInterface $form, string $name, mixed $data): void
    {
        $config = $form->get($name)->getConfig();
        $form->add($name, $config->getType()->getInnerType()::class, ['disabled' => true, 'data' => $data] + array_diff_key($config->getOptions(), ['data' => 1, 'disabled' => 1]));
    }
}

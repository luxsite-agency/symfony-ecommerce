<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Catalog\Entity\Category;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Pricing\Promotion\Enum\PromotionType;
use App\Core\Shared\Translation\ExceptionMessage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Акції (Ф3): % або сума на категорії/бренди/товари з датами й пріоритетом. Лише ROLE_ADMIN — це ціноутворення.
 *
 * @extends AbstractCrudController<Promotion>
 */
#[IsGranted('ROLE_ADMIN')]
final class PromotionCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public function __construct(
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Promotion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('promotion.label.singular')
            ->setEntityLabelInPlural('promotion.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'promotion.title.new')
            ->setEntityPermission('ROLE_ADMIN')
            ->setSearchFields(['name'])
            ->setDefaultSort(['priority' => 'DESC', 'id' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'promotion.help.index');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'promotion.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield FormField::addFieldset('promotion.fieldset.discount');
        yield TextField::new('name', 'promotion.field.name')->setHelp('promotion.help.name');
        yield BooleanField::new('active', 'promotion.field.active');
        // Тип і значення не мапляться на сетери по одному: їх разом застосовує withAtomicDiscount() (атомарні сетери).
        // варіанти — з enumType PromotionType (EnumType), підписи — його trans() мовою адмінки
        yield ChoiceField::new('type', 'promotion.field.type')
            ->setFormTypeOptions(['mapped' => false, 'choice_value' => static fn (?PromotionType $t): ?string => $t?->value])
            ->onlyOnForms();
        yield TextField::new('discount', 'promotion.field.discount')->setFormTypeOption('mapped', false)->onlyOnForms()
            ->setHelp(t('promotion.help.discount', ['currency' => $this->currency], 'admin'));
        yield TextField::new('summary', 'promotion.field.discount')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig');
        yield IntegerField::new('priority', 'promotion.field.priority')->setHelp('promotion.help.priority');
        yield BooleanField::new('stackable', 'promotion.field.stackable')->hideOnIndex();
        yield FormField::addFieldset('promotion.fieldset.when');
        yield DateTimeField::new('startsAt', 'promotion.field.starts_at')->setRequired(false);
        yield DateTimeField::new('endsAt', 'promotion.field.ends_at')->setRequired(false)->setHelp('promotion.help.ends_at');
        yield FormField::addFieldset('promotion.fieldset.targets')->setHelp('promotion.help.targets');
        yield BooleanField::new('wholeCatalog', 'promotion.field.whole_catalog')->renderAsSwitch(false);
        yield AssociationField::new('categories', 'promotion.field.categories')->hideOnIndex()->setRequired(false)
            ->setFormTypeOptions(['by_reference' => false, 'choice_label' => static fn (Category $c): string => $c->getPathLabel()]);
        yield AssociationField::new('brands', 'promotion.field.brands')->hideOnIndex()->setRequired(false)->setFormTypeOption('by_reference', false);
        yield AssociationField::new('products', 'promotion.field.products')->hideOnIndex()->setRequired(false)->autocomplete()->setFormTypeOption('by_reference', false);
        yield FormField::addFieldset('promotion.fieldset.audience')->setHelp('promotion.help.audience');
        yield AssociationField::new('customerGroups', 'promotion.field.customer_groups')->hideOnIndex()->setRequired(false)->setFormTypeOption('by_reference', false);
    }

    public function createEntity(string $entityFqcn): Promotion
    {
        return new Promotion($this->translator->trans('promotion.default_name', [], 'admin'), $this->currency);
    }

    /** @return FormBuilderInterface<mixed> */
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withAtomicDiscount(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    /** @return FormBuilderInterface<mixed> */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withAtomicDiscount(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * «Тип» і «Знижка» — немаплені поля: заповнюються з акції, а після сабміту застосовуються одним атомарним сетером.
     * Невалідне значення — помилка поля, не 500. Пріоритет вище за валідацію форми, щоб вона бачила вже змінену акцію.
     *
     * @param FormBuilderInterface<mixed> $builder
     *
     * @return FormBuilderInterface<mixed>
     */
    private function withAtomicDiscount(FormBuilderInterface $builder): FormBuilderInterface
    {
        // помилки FormError показуються як є, без перекладу — перекладаємо тут мовою адмінки
        $translator = $this->translator;
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $promotion = $event->getData();
            if (!$promotion instanceof Promotion) {
                return;
            }
            $event->getForm()->get('type')->setData($promotion->getType());
            if ($promotion->getValue() > 0) {
                $event->getForm()->get('discount')->setData($promotion->getAmountDecimal() ?? rtrim(rtrim(number_format($promotion->getValue() / 100, 2, '.', ''), '0'), '.'));
            }
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($translator): void {
            $promotion = $event->getData();
            if (!$promotion instanceof Promotion) {
                return;
            }
            $form = $event->getForm();
            $type = $form->get('type')->getData();
            $discount = trim((string) $form->get('discount')->getData());
            if (!$type instanceof PromotionType) {
                $form->get('type')->addError(new FormError($translator->trans('promotion.error.type_required', [], 'admin')));

                return;
            }
            if ('' === $discount) {
                $form->get('discount')->addError(new FormError($translator->trans('promotion.error.discount_required', [], 'admin')));

                return;
            }
            try {
                match ($type) {
                    PromotionType::Percent => $promotion->setPercentDiscount($discount),
                    PromotionType::Amount => $promotion->setAmountDiscount($discount),
                };
            } catch (InvalidArgumentException $e) {
                $form->get('discount')->addError(new FormError(ExceptionMessage::trans($e, $translator)));
            }
        }, 10);

        return $builder;
    }
}

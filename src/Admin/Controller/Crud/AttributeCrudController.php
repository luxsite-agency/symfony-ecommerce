<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\AttributeValueType;
use App\Admin\Form\CollectionSync;
use App\Admin\Form\Translation\SingleFieldTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeTranslation;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Enum\AttributeType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Характеристики (EAV) зі значеннями для select/multiselect. Тип незмінний після створення. *.
 * @extends AbstractCrudController<Attribute>
 */
final class AttributeCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return Attribute::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('attribute.label.singular')
            ->setEntityLabelInPlural('attribute.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'attribute.title.new')
            ->setSearchFields(['code', 'translations.name'])
            ->setDefaultSort(['position' => 'ASC', 'code' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'attribute.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield FormField::addFieldset('attribute.fieldset.main')->onlyOnForms();
        yield TextField::new('code', 'attribute.field.code')
            ->setHelp('attribute.help.code')
            ->setFormTypeOption('setter', static fn (Attribute $a, ?string $code) => $a->changeCode((string) $code));
        yield ChoiceField::new('type', 'attribute.field.type')
            ->setDisabled(Crud::PAGE_EDIT === $pageName)
            ->setFormTypeOption('choice_value', static fn (?AttributeType $t): ?string => $t?->value)
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'attribute.help.type_locked' : '')
            ->setFormTypeOption('setter', static fn (Attribute $a, ?AttributeType $type) => $a->changeType($type ?? AttributeType::Select));
        yield TextField::new('unit', 'attribute.field.unit')->setHelp('attribute.help.unit')->hideOnIndex();
        yield BooleanField::new('filterable', 'attribute.field.filterable')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield BooleanField::new('variantAxis', 'attribute.field.variant_axis')->setHelp('attribute.help.variant_axis')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield IntegerField::new('position', 'attribute.field.position');
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => SingleFieldTranslationType::class, 'entry_options' => ['data_class' => AttributeTranslation::class]]);
        yield FormField::addFieldset('attribute.fieldset.values')->onlyOnForms()->setHelp('attribute.help.values');
        yield CollectionField::new('values', false)
            ->onlyOnForms()
            ->setEntryType(AttributeValueType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOptions([
                'by_reference' => false,
                'setter' => CollectionSync::setter(
                    static fn (Attribute $a) => $a->getValues(),
                    static fn (Attribute $a, AttributeValue $v) => $a->removeValue($v),
                ),
            ]);
        yield IntegerField::new('valuesCount', 'attribute.field.values_count')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/integer.html.twig')
            ->formatValue(static fn ($v, ?Attribute $a): int => $a?->getValues()->count() ?? 0);
    }

    public function createEntity(string $entityFqcn): Attribute
    {
        // Код і тип задає форма (changeCode / changeType до збереження).
        return new Attribute('attr_'.bin2hex(random_bytes(3)), AttributeType::Select);
    }
}

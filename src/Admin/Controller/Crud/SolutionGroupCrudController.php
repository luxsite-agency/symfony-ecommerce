<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Translation\SolutionGroupTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Content\Entity\SolutionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Групи рішень (розділи сторінки списку): назва мовами й порядок. Видалення групи лишає її рішення без групи (SET NULL).
 *
 * @extends AbstractCrudController<SolutionGroup>
 */
final class SolutionGroupCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return SolutionGroup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('solution_group.label.singular')
            ->setEntityLabelInPlural('solution_group.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'solution_group.title.new')
            ->setSearchFields(['translations.title'])
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'solution_group.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'solution.field.id')->onlyOnIndex();
        yield TextField::new('title', 'solution_group.field.title')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?SolutionGroup $g): string => (string) $g);
        yield IntegerField::new('position', 'solution.field.position');
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => SolutionGroupTranslationType::class]);
    }

    public function createEntity(string $entityFqcn): SolutionGroup
    {
        return new SolutionGroup();
    }
}

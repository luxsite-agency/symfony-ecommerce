<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Translation\CategoryTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Дерево категорій через parent_id (ADR D3). Список показує шлях «Двері / Міжкімнатні»; батько обирається
 * зі списку шляхів. Цикл (категорія — власний предок) відхиляє Category::setParent(). Зображення плитки — з медіатеки.
 *
 * @extends AbstractCrudController<Category>
 */
final class CategoryCrudController extends AbstractCrudController
{
    use SaveActionsTrait;
    use ViewOnSiteTrait;

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('category.label.singular')
            ->setEntityLabelInPlural('category.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'category.title.new')
            ->setSearchFields(['translations.name', 'translations.slug'])
            ->setDefaultSort(['parent' => 'ASC', 'position' => 'ASC'])
            ->setPaginatorPageSize(50);
    }

    public function __construct(private readonly SiteUrlGenerator $siteUrls)
    {
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->addViewOnSiteAction($actions, fn (object $c): ?string => $c instanceof Category ? $this->siteUrls->category($c) : null);

        return $this->configureFormActions($actions, 'category.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('pathLabel', 'category.field.category')->onlyOnIndex();
        yield TextField::new('pathLabel', 'category.field.path')->onlyOnDetail();
        yield FormField::addFieldset('category.fieldset.placement')->onlyOnForms();
        yield AssociationField::new('parent', 'category.field.parent')
            ->setRequired(false)
            ->hideOnIndex()
            ->setFormTypeOptions([
                'choice_label' => static fn (Category $c): string => $c->getPathLabel(),
                'placeholder' => 'category.placeholder.root',
                'query_builder' => static fn ($repo): QueryBuilder => $repo->createQueryBuilder('c')->orderBy('c.position', 'ASC'),
            ]);
        yield IntegerField::new('position', 'category.field.position');
        yield BooleanField::new('active', 'category.field.active');
        yield AssociationField::new('image', 'category.field.image')->setRequired(false)->hideOnIndex()
            ->setFormTypeOptions(['choice_label' => static fn (Media $m): string => (string) $m, 'query_builder' => static fn ($repo) => $repo->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')])
            ->setHelp('category.help.image');
        yield FormField::addFieldset('category.fieldset.texts')->onlyOnForms();
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => CategoryTranslationType::class]);
    }

    public function createEntity(string $entityFqcn): Category
    {
        return new Category();
    }
}

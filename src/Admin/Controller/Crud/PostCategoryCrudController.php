<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Translation\NameSlugTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Content\Entity\PostCategory;
use App\Core\Content\Entity\PostCategoryTranslation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends AbstractCrudController<PostCategory> */
final class PostCategoryCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return PostCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('post_category.label.singular')
            ->setEntityLabelInPlural('post_category.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'post_category.title.new')
            ->setSearchFields(['code', 'translations.name'])
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'post_category.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('name', 'post_category.field.name')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?PostCategory $c): string => (string) $c);
        yield TextField::new('code', 'post_category.field.code')
            ->setHelp('post_category.help.code')
            ->setFormTypeOption('setter', static fn (PostCategory $c, ?string $code) => $c->changeCode((string) $code));
        yield IntegerField::new('position', 'post_category.field.position');
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => NameSlugTranslationType::class, 'entry_options' => ['data_class' => PostCategoryTranslation::class]]);
    }

    public function createEntity(string $entityFqcn): PostCategory
    {
        return new PostCategory('rubric_'.bin2hex(random_bytes(3)));
    }
}

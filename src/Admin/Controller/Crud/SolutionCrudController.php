<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Content\SolutionOptionType;
use App\Admin\Form\Translation\SolutionTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Media;
use App\Core\Content\Entity\Solution;
use App\Site\SiteUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Рішення (docs/solutions.md): публікація, група, обкладинка, інші рішення, контент мовами (як Page), варіанти — товари з
 * перевагами мовами. Права як у Page (весь хост адмінки — ROLE_MANAGER). Підписи — ключі домену admin.
 *
 * @extends AbstractCrudController<Solution>
 */
final class SolutionCrudController extends AbstractCrudController
{
    use SaveActionsTrait;
    use ViewOnSiteTrait;

    public function __construct(private readonly SiteUrlGenerator $siteUrls)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Solution::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('solution.label.singular')
            ->setEntityLabelInPlural('solution.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'solution.title.new')
            // розумний пошук адмінки (AdminSearchQuery: усі слова — AND): назва, URL, вступ, група й товари рішення
            ->setSearchFields(['translations.title', 'translations.slug', 'translations.intro', 'group.translations.title', 'options.product.translations.name'])
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->addViewOnSiteAction($actions->add(Crud::PAGE_INDEX, Action::DETAIL), fn (object $solution): ?string => $solution instanceof Solution ? $this->siteUrls->solution($solution) : null);

        return $this->configureFormActions($actions, 'solution.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'solution.field.id')->onlyOnIndex();
        yield TextField::new('title', 'solution.field.title')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Solution $s): string => (string) $s);
        yield FormField::addFieldset('solution.section.publishing')->onlyOnForms();
        yield BooleanField::new('published', 'solution.field.published')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield IntegerField::new('position', 'solution.field.position');
        yield AssociationField::new('group', 'solution.field.group')->setRequired(false);
        yield AssociationField::new('cover', 'solution.field.cover')->setRequired(false)->hideOnIndex()
            ->setFormTypeOptions(['choice_label' => static fn (Media $m): string => (string) $m, 'query_builder' => static fn ($repo) => $repo->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')])
            ->setHelp('solution.help.cover');
        yield AssociationField::new('relatedSolutions', 'solution.field.related')->setRequired(false)->hideOnIndex()
            ->setFormTypeOption('by_reference', false)->setHelp('solution.help.related');
        yield DateTimeField::new('updatedAt', 'solution.field.updated_at')->hideOnForm();
        yield FormField::addFieldset('solution.section.options')->onlyOnForms()->setHelp('solution.help.options');
        yield CollectionField::new('options', false)
            ->onlyOnForms()
            ->setEntryType(SolutionOptionType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOption('by_reference', false);
        yield FormField::addFieldset('solution.section.content')->onlyOnForms();
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => SolutionTranslationType::class]);
    }

    public function createEntity(string $entityFqcn): Solution
    {
        return new Solution();
    }
}

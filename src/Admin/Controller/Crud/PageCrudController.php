<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Translation\PageTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Content\Entity\Page;
use App\Site\SiteUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Статичні сторінки: публікація, позиція, меню/футер, контент uk/ru однією формою (WYSIWYG — TextEditorField/Trix).
 *
 * @extends AbstractCrudController<Page>
 */
final class PageCrudController extends AbstractCrudController
{
    use SaveActionsTrait;
    use ViewOnSiteTrait;

    public function __construct(private readonly SiteUrlGenerator $siteUrls)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Page::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('page.label.singular')
            ->setEntityLabelInPlural('page.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'page.title.new')
            ->setSearchFields(['translations.title', 'translations.slug'])
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->addViewOnSiteAction($actions->add(Crud::PAGE_INDEX, Action::DETAIL), fn (object $page): ?string => $page instanceof Page ? $this->siteUrls->page($page) : null);

        return $this->configureFormActions($actions, 'page.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('title', 'page.field.title')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Page $p): string => (string) $p);
        yield TextField::new('slug', 'page.field.slug')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(fn ($v, ?Page $p): string => $p?->getTranslation($this->siteUrls->defaultLocale())?->getSlug() ?? '');
        yield FormField::addFieldset('page.section.publishing')->onlyOnForms();
        yield BooleanField::new('published', 'page.field.published')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield IntegerField::new('position', 'page.field.position');
        yield BooleanField::new('showInHeader', 'page.field.show_in_header')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield BooleanField::new('showInFooter', 'page.field.show_in_footer')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield DateTimeField::new('updatedAt', 'page.field.updated_at')->hideOnForm();
        yield FormField::addFieldset('page.section.content')->onlyOnForms();
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => PageTranslationType::class]);
    }

    public function createEntity(string $entityFqcn): Page
    {
        return new Page();
    }
}

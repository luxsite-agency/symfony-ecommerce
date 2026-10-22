<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Translation\PostTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Media;
use App\Core\Content\Entity\Post;
use App\Site\SiteUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Записи блогу: рубрика, дата публікації (порожня/майбутня — не публічний), обкладинка з медіатеки, автор і теги рядками,
 * контент uk/ru однією формою.
 *
 * @extends AbstractCrudController<Post>
 */
final class PostCrudController extends AbstractCrudController
{
    use SaveActionsTrait;
    use ViewOnSiteTrait;

    public function __construct(private readonly SiteUrlGenerator $siteUrls)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Post::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('post.label.singular')
            ->setEntityLabelInPlural('post.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'post.title.new')
            ->setSearchFields(['translations.title', 'translations.slug', 'authorName', 'tags'])
            ->setDefaultSort(['publishedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->addViewOnSiteAction($actions->add(Crud::PAGE_INDEX, Action::DETAIL), fn (object $post): ?string => $post instanceof Post ? $this->siteUrls->post($post) : null);

        return $this->configureFormActions($actions, 'post.action.add');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('category', 'post.field.category'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield ImageField::new('coverPath', 'post.field.cover')->onlyOnIndex()->setSortable(false)
            ->setBasePath('/media/cache/resolve/admin_thumb/media')
            ->setTemplatePath('@EasyAdmin/crud/field/image.html.twig')
            ->formatValue(static fn ($v, ?Post $p): ?string => null === $p?->getCover() ? null : '/media/cache/resolve/admin_thumb/media/'.$p->getCover()->getPath());
        yield TextField::new('title', 'post.field.title')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Post $p): string => (string) $p);
        yield FormField::addFieldset('post.section.publishing')->onlyOnForms();
        yield AssociationField::new('category', 'post.field.category')->setRequired(false);
        yield DateTimeField::new('publishedAt', 'post.field.published_at')->setRequired(false)->setHelp('post.help.published_at');
        yield AssociationField::new('cover', 'post.field.cover')->setRequired(false)->hideOnIndex()
            ->setFormTypeOptions(['choice_label' => static fn (Media $m): string => (string) $m, 'query_builder' => static fn ($repo) => $repo->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')])
            ->setHelp('post.help.cover');
        yield TextField::new('authorName', 'post.field.author')->setRequired(false)->hideOnIndex();
        yield TextField::new('tags', 'post.field.tags')->setRequired(false)->setHelp('post.help.tags')->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'post.field.updated_at')->hideOnForm();
        yield FormField::addFieldset('post.section.content')->onlyOnForms();
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => PostTranslationType::class]);
    }

    public function createEntity(string $entityFqcn): Post
    {
        return new Post();
    }
}

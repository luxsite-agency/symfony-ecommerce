<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Catalog\Entity\Media;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Медіатека: файл вантажиться через Vich у public/media (Media.path — ім'я файлу), прев'ю — Liip admin_thumb.
 * Файл існуючого запису не замінюється (шлях у товарах стабільний): завантажується новий Media.
 * Маршрут /library, бо /media/* віддає nginx як статику.
 *
 * @extends AbstractCrudController<Media>
 */
#[AdminRoute(path: '/library', name: 'library')]
final class MediaCrudController extends AbstractCrudController
{
    use ImageFieldsTrait;
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('media.label.singular')
            ->setEntityLabelInPlural('media.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'media.title.new')
            ->setSearchFields(['path', 'alt'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions->add(Crud::PAGE_INDEX, Action::DETAIL), 'media.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield from $this->imageFields($pageName, label: 'media.field.image');
        yield TextField::new('path', 'media.field.path')->hideOnForm();
        yield TextField::new('mimeType', 'media.field.mime_type')->onlyOnDetail();
        yield IntegerField::new('sizeBytes', 'media.field.size')->hideOnForm();
        yield IntegerField::new('width', 'media.field.width')->onlyOnDetail();
        yield IntegerField::new('height', 'media.field.height')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'media.field.created_at')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): Media
    {
        // Vich на prePersist перепише path/mimeType/sizeBytes реальними значеннями файлу.
        return new Media('pending-'.bin2hex(random_bytes(6)), 'application/octet-stream', 0);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * «Переглянути на сайті» на списку, перегляді й редагуванні: відкриває публічний URL у новій вкладці;
 * недоступна (не показується), поки сутність не опублікована або не має слага мовою за замовчуванням.
 */
trait ViewOnSiteTrait
{
    /** @param callable(object): ?string $url публічний URL або null, якщо сторінки ще нема */
    private function addViewOnSiteAction(Actions $actions, callable $url): Actions
    {
        $action = Action::new('viewOnSite', 'action.view_on_site', 'fa fa-external-link')
            ->linkToUrl(static fn (object $entity): string => (string) $url($entity))
            ->displayIf(static fn (object $entity): bool => null !== $url($entity))
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener']);

        return $actions
            ->add(Crud::PAGE_INDEX, $action)
            ->add(Crud::PAGE_DETAIL, $action)
            ->add(Crud::PAGE_EDIT, $action);
    }
}

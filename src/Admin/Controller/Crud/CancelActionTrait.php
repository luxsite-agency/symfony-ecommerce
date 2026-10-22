<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Кнопка «Вийти без збереження» на формах створення/редагування — повертає у список, зміни не зберігаються.
 * Портовано з внутрішнього проєкту LuxSite (admin-ux-ref).
 */
trait CancelActionTrait
{
    private function addCancelAction(Actions $actions): Actions
    {
        $cancel = Action::new('cancel', 'action.cancel', 'fa fa-xmark')
            ->linkToCrudAction(Action::INDEX)
            ->setCssClass('btn btn-secondary action-cancel');

        return $actions
            ->add(Crud::PAGE_EDIT, $cancel)
            ->add(Crud::PAGE_NEW, $cancel);
    }
}

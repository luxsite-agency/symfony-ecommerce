<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Однакові кнопки форм у всіх CRUD: «Зберегти», «Зберегти і продовжити редагування» (edit), «Зберегти і створити ще» (new),
 * «Вийти без збереження». EasyAdmin додає save-кнопки сам — тут лише підписи й порядок. $addLabel — кнопка створення на списку
 * в знахідному відмінку («Додати категорію»): типове «Створити %entity_label_singular%» EasyAdmin дає «Створити Категорія» (аудит UX A8).
 * Підписи — ключі домену admin: власні кнопки трейту — action.*, $addLabel — ключ контролера (напр. category.action.add).
 */
trait SaveActionsTrait
{
    use CancelActionTrait;

    private function configureFormActions(Actions $actions, TranslatableInterface|string|null $addLabel = null): Actions
    {
        if (null !== $addLabel) {
            $actions = $actions->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $a): Action => $a->setLabel($addLabel));
        }
        $actions = $this->addCancelAction($actions)
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, static fn (Action $a): Action => $a->setLabel('action.create'))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER, static fn (Action $a): Action => $a->setLabel('action.save_and_add_another'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, static fn (Action $a): Action => $a->setLabel('action.save'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, static fn (Action $a): Action => $a->setLabel('action.save_and_continue'));

        return $actions
            ->reorder(Crud::PAGE_NEW, [Action::SAVE_AND_RETURN, Action::SAVE_AND_ADD_ANOTHER, 'cancel'])
            ->reorder(Crud::PAGE_EDIT, [Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, 'cancel']);
    }
}

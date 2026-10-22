<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\B2b\Entity\Company;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Компанії оптових клієнтів (E13): довідник, створюється лише схваленням заявки. Редагуються назва, ПДВ і контакт;
 * код (ЄДРПОУ/РНОКПП) і склад компанії змінюються лише через нову заявку.
 *
 * @extends AbstractCrudController<Company>
 */
final class CompanyCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return Company::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('company.label.singular')
            ->setEntityLabelInPlural('company.label.plural')
            ->setSearchFields(['name', 'taxId', 'contactName', 'members.customer.email'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions->add(Crud::PAGE_INDEX, Action::DETAIL))
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    /** Список: члени компанії й покупці одним запитом (колонка «Покупець» — company.field.members), без N+1. Аліаси — не «members»: його бере пошук EasyAdmin (members.customer.email). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('company_members', 'memberCustomer')
            ->leftJoin('entity.members', 'company_members')
            ->leftJoin('company_members.customer', 'memberCustomer');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('name', 'company.field.name')
            ->setFormTypeOption('setter', static fn (Company $c, ?string $v) => $c->rename((string) $v));
        yield TextField::new('taxId', 'company.field.tax_id')->setDisabled();
        yield BooleanField::new('vatPayer', 'company.field.vat_payer')->renderAsSwitch(false);
        yield TextField::new('contactName', 'company.field.contact_name')
            ->setFormTypeOption('setter', static fn (Company $c, ?string $v) => $c->setContact((string) $v, $c->getContactPhone()));
        yield TextField::new('contactPhone', 'company.field.contact_phone')->setRequired(false)
            ->setFormTypeOption('setter', static fn (Company $c, ?string $v) => $c->setContact($c->getContactName(), $v));
        yield TextField::new('memberEmails', 'company.field.members')->hideOnForm()->setSortable(false);
        yield DateTimeField::new('createdAt', 'company.field.created_at')->hideOnForm();
    }
}

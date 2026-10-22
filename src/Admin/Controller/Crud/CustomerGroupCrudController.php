<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\Product\MoneyInput;
use App\Core\Customer\Entity\CustomerGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Групи клієнтів для порогів цін (ADR Rev 7) — розділ «Налаштування», лише ROLE_ADMIN. B2B-мінімум замовлення
 * (сума у валюті магазину, кількість одиниць) і точні залишки для групи (E13 PR-7).
 *
 * @extends AbstractCrudController<CustomerGroup>
 */
#[IsGranted('ROLE_ADMIN')]
final class CustomerGroupCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public function __construct(
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return CustomerGroup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('customer_group.label.singular')
            ->setEntityLabelInPlural('customer_group.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'customer_group.title.new')
            ->setEntityPermission('ROLE_ADMIN')
            ->setSearchFields(['code', 'name'])
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // прайс групи (E13 PR-8): сам файл віддає admin_price_list (ROLE_MANAGER), кнопка тут — для адміна на списку груп
        $priceList = Action::new('priceList', 'customer_group.action.price_list', 'fa fa-file-csv')
            ->linkToRoute('admin_price_list', static fn (CustomerGroup $group): array => ['id' => $group->getId()]);

        return $this->configureFormActions($actions->add(Crud::PAGE_INDEX, $priceList)->add(Crud::PAGE_EDIT, $priceList), 'customer_group.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('code', 'customer_group.field.code')
            ->setHelp('customer_group.help.code')
            ->setFormTypeOption('setter', static fn (CustomerGroup $group, ?string $code) => $group->changeCode((string) $code));
        yield TextField::new('name', 'customer_group.field.name')
            ->setFormTypeOption('setter', static fn (CustomerGroup $group, ?string $name) => $group->rename((string) $name));
        yield IntegerField::new('position', 'customer_group.field.position');
        yield TextField::new('minOrderAmountDecimal', t('customer_group.field.min_order_amount', ['currency' => $this->currency], 'admin'))
            ->setHelp('customer_group.help.min_order_amount')
            ->setFormTypeOptions([
                'getter' => fn (CustomerGroup $group): ?string => MoneyInput::format($group->getMinOrderAmount($this->currency)),
                'setter' => fn (CustomerGroup $group, ?string $raw) => $group->setMinOrderAmount(MoneyInput::parse($raw, $this->currency)),
            ]);
        yield TextField::new('minOrderQuantity', 'customer_group.field.min_order_quantity')
            ->setHelp('customer_group.help.min_order_quantity')
            ->setFormTypeOption('setter', static fn (CustomerGroup $group, ?string $raw) => $group->setMinOrderQuantity($raw));
        yield BooleanField::new('exactStock', 'customer_group.field.exact_stock')
            ->setHelp('customer_group.help.exact_stock')
            ->setFormTypeOption('getter', static fn (CustomerGroup $group): bool => $group->showsExactStock())
            ->renderAsSwitch(false);
    }

    public function createEntity(string $entityFqcn): CustomerGroup
    {
        return new CustomerGroup('group_'.bin2hex(random_bytes(3)), $this->translator->trans('customer_group.default_name', [], 'admin'));
    }
}

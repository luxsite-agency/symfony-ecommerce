<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\Enum\ApplicationStatus;
use App\Core\B2b\WholesaleApplicationException;
use App\Core\B2b\WholesaleApplications;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Security\Entity\User;
use App\Core\Shared\Translation\ExceptionMessage;
use App\Site\Account\WholesaleMails;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Заявки оптовиків (E13.1, PR-5): список з фільтром за станом, перегляд реквізитів, схвалення з вибором групи цін і
 * відхилення з причиною (сторінка перегляду, POST з CSRF). Заявка незмінна: створення, редагування й видалення
 * вимкнені. Рішення пише WholesaleApplications (група, компанія, security_audit), лист покупцю — WholesaleMails.
 *
 * @extends AbstractCrudController<WholesaleApplication>
 */
final class WholesaleApplicationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WholesaleApplications $applications,
        private readonly WholesaleMails $mails,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WholesaleApplication::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('wholesale.label.singular')
            ->setEntityLabelInPlural('wholesale.label.plural')
            ->setSearchFields(['companyName', 'taxId', 'contactName', 'customer.email'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->overrideTemplate('crud/detail', 'admin/b2b/application_detail.html.twig')
            ->setHelp(Crud::PAGE_INDEX, 'wholesale.help.index');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    /** Сторінка перегляду: список груп цін для вибору при схваленні (templates/admin/b2b). */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $responseParameters->set('groups', $this->em->getRepository(CustomerGroup::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']));
        }

        return $responseParameters;
    }

    public function configureFilters(Filters $filters): Filters
    {
        // підписи варіантів — ключі enum.application_status.* у домені admin (ChoiceFilter типово перекладає в EasyAdminBundle)
        return $filters
            ->add(ChoiceFilter::new('status', 'wholesale.filter.status')
                ->setChoices(array_combine(array_map(static fn (ApplicationStatus $s): string => $s->transKey(), ApplicationStatus::cases()), array_map(static fn (ApplicationStatus $s): string => $s->value, ApplicationStatus::cases())))
                ->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'wholesale.field.created_at');
        // status — enumType з TranslatableInterface: варіанти й підписи EasyAdmin бере з enum, бейджі — за назвою case
        yield ChoiceField::new('status', 'wholesale.field.status')
            ->renderAsBadges([ApplicationStatus::New->name => 'warning', ApplicationStatus::Approved->name => 'success', ApplicationStatus::Rejected->name => 'secondary']);
        yield TextField::new('companyName', 'wholesale.field.company_name');
        yield TextField::new('taxId', 'wholesale.field.tax_id');
        yield BooleanField::new('vatPayer', 'wholesale.field.vat_payer')->renderAsSwitch(false);
        yield TextField::new('contactName', 'wholesale.field.contact_name');
        yield TextField::new('contactPhone', 'wholesale.field.contact_phone')->hideOnIndex();
        yield TextField::new('customer.email', 'wholesale.field.customer_email');
        yield TextField::new('customer.name', 'wholesale.field.customer_name')->onlyOnDetail();
        yield TextareaField::new('note', 'wholesale.field.note')->onlyOnDetail();
        yield AssociationField::new('assignedGroup', 'wholesale.field.assigned_group')->setSortable(false);
        yield AssociationField::new('company', 'wholesale.field.company')->onlyOnDetail();
        yield TextField::new('reviewedByEmail', 'wholesale.field.reviewed_by')->onlyOnDetail();
        yield DateTimeField::new('reviewedAt', 'wholesale.field.reviewed_at')->onlyOnDetail();
        yield TextareaField::new('reviewComment', 'wholesale.field.review_comment')->onlyOnDetail();
    }

    /** Список: покупець одним запитом (колонка «Email покупця»), без N+1. Аліас — не «customer»: його бере пошук EasyAdmin (customer.email). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('application_customer')->join('entity.customer', 'application_customer')
            ->addSelect('assignedGroup')->leftJoin('entity.assignedGroup', 'assignedGroup');
    }

    /**
     * Рішення з кнопок сторінки перегляду: POST decision=approve|reject, group (id групи при схваленні),
     * comment (причина при відхиленні обовʼязкова) + CSRF-токен wholesale-decision-<id>.
     *
     * @param AdminContext<WholesaleApplication> $context
     */
    #[AdminRoute(path: '/{entityId}/decide', name: 'decide', options: ['methods' => ['POST']])]
    public function decide(AdminContext $context, Request $request): Response
    {
        $application = $context->getEntity()->getInstance();
        if (!$application instanceof WholesaleApplication) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wholesale-decision-'.$application->getId(), (string) $request->request->get('token', ''))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $comment = trim((string) $request->request->get('comment', ''));
        try {
            switch ((string) $request->request->get('decision', '')) {
                case 'approve':
                    $groupId = (string) $request->request->get('group', '');
                    $group = ctype_digit($groupId) ? $this->em->find(CustomerGroup::class, (int) $groupId) : null;
                    if (null === $group) {
                        throw new WholesaleApplicationException('Оберіть групу цін', 'wholesale.error.group_required');
                    }
                    $this->applications->approve($application, $reviewer, $group, '' === $comment ? null : $comment);
                    $this->mails->approved($application);
                    $this->addFlash('success', t('wholesale.flash.approved', ['id' => (string) $application->getId(), 'group' => htmlspecialchars($group->getName(), \ENT_QUOTES)], 'admin'));
                    break;
                case 'reject':
                    if ('' === $comment) {
                        throw new WholesaleApplicationException('Вкажіть причину відхилення, вона піде покупцю', 'wholesale.error.reason_required');
                    }
                    $this->applications->reject($application, $reviewer, $comment);
                    $this->mails->rejected($application);
                    $this->addFlash('success', t('wholesale.flash.rejected', ['id' => (string) $application->getId()], 'admin'));
                    break;
                default:
                    throw new WholesaleApplicationException('Невідоме рішення', 'wholesale.error.unknown_decision');
            }
        } catch (WholesaleApplicationException|InvalidArgumentException $e) {
            // флеші EasyAdmin рендеряться |raw: у них лише свої тексти, без реквізитів заявки, і все одно екрановано
            $this->addFlash('danger', htmlspecialchars(ExceptionMessage::trans($e, $this->translator), \ENT_QUOTES));
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->setController(self::class)->setAction(Action::DETAIL)->setEntityId($application->getId())->generateUrl());
    }
}

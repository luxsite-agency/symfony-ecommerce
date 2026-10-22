<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditedEntities;
use App\Core\Audit\AuditSource;
use App\Core\Audit\Entity\AdminAuditEntry;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\QueryBuilder;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Журнал змін (E15 PR-1): хто, коли, звідки й що змінив у каталозі, цінах і контенті — лише читання. Видно всім
 * користувачам адмінки: за рішенням власника 11.10.2026 за ціни й асортимент відповідають менеджери, тож журнал
 * потрібен їм, а не лише адміністратору. Персональних даних покупців тут нема за побудовою. Рядки налаштувань магазину
 * (`setting`) бачить лише ROLE_ADMIN — як і самі налаштування (ADR D10). Тексти — ключі audit.* домену admin; назви джерел,
 * дій і типів — enum.audit_source/enum.audit_action/entity_type.*.
 *
 * @extends AbstractCrudController<AdminAuditEntry>
 */
final class AdminAuditCrudController extends AbstractCrudController
{
    public function __construct(
        #[Autowire('%app.admin_audit_months%')] private readonly int $months,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AdminAuditEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('audit.label.singular')
            ->setEntityLabelInPlural('audit.label.plural')
            // __toString() сутності український — заголовок сторінки запису збирається з перекладів
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (AdminAuditEntry $e) => t('audit.title.detail', ['action' => $e->getAction(), 'type' => AuditedEntities::typeMessage($e->getEntityType()), 'id' => (string) $e->getEntityId()], 'admin'))
            ->setSearchFields(['entityLabel', 'field', 'actor'])
            ->setDefaultSort(['occurredAt' => 'DESC', 'id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->hideNullValues()
            ->setHelp(Crud::PAGE_INDEX, $this->months > 0 ? t('audit.help.index_retention', ['months' => (string) $this->months], 'admin') : 'audit.help.index');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->isGranted(UserRole::Admin->value)) {
            $qb->andWhere('entity.entityType <> :adminOnlyType')->setParameter('adminOnlyType', AuditedEntities::ADMIN_ONLY_TYPE);
        }

        return $qb;
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $entry = $context->getEntity()->getInstance();
        if ($entry instanceof AdminAuditEntry && AuditedEntities::ADMIN_ONLY_TYPE === $entry->getEntityType() && !$this->isGranted(UserRole::Admin->value)) {
            throw new AccessDeniedException($this->translator->trans('audit.error.admin_only', [], 'admin'));
        }

        return parent::detail($context);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // setTranslatableChoices: назви мовою адмінки; setChoices перекладав би в домені EasyAdminBundle
            ->add(ChoiceFilter::new('source', 'audit.field.source')->setTranslatableChoices(AuditSource::cases()))
            ->add(ChoiceFilter::new('action', 'audit.field.action')->setTranslatableChoices(AuditAction::cases()))
            ->add(ChoiceFilter::new('entityType', 'audit.field.entity_type')->setTranslatableChoices(AuditedEntities::typeChoices()))
            ->add(NumericFilter::new('entityId', 'audit.filter.entity_id'))
            ->add(TextFilter::new('actor', 'audit.field.actor'))
            ->add(NumericFilter::new('userId', 'audit.field.user_id'))
            ->add(NumericFilter::new('changesetId', 'audit.field.changeset'))
            ->add(DateTimeFilter::new('occurredAt', 'audit.field.occurred_at'));
    }

    public function configureFields(string $pageName): iterable
    {
        $index = Crud::PAGE_INDEX === $pageName;

        // дата в один рядок і без секунд; на списку — 6 колонок замість 11, щоб таблиця вміщалась на 1280 без прокрутки (аудит UX A12)
        yield DateTimeField::new('occurredAt', 'audit.field.occurred_at')->setFormat('dd.MM.yyyy HH:mm')->addCssClass('text-nowrap');
        yield TextField::new('actor', 'audit.field.actor');
        // enum-и з TranslatableInterface: EasyAdmin сам бере choices з enumType і показує trans(); setChoices([назва => значення]) дав би порожні клітинки
        yield ChoiceField::new('source', 'audit.field.source');
        yield ChoiceField::new('action', 'audit.field.action');
        if ($index) {
            // «Товар «…» #id» і «Назва (uk): було → стало» мовою адмінки; text_no_title — без title з українським сирим значенням
            yield TextField::new('subjectText', 'audit.field.entity_type')->setSortable(false)->setTemplatePath('admin/field/text_no_title.html.twig')
                ->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e ? '' : $e->getSubjectText($this->translator));
            yield TextField::new('changeSummary', 'audit.field.change')->setSortable(false)->setTemplatePath('admin/field/text_no_title.html.twig')
                ->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e ? '' : $e->getChangeSummary(80, $this->translator));

            return;
        }
        yield TextField::new('entityTypeLabel', 'audit.field.entity_type')->setTemplatePath('admin/field/text_no_title.html.twig')->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e ? '' : AuditedEntities::typeMessage($e->getEntityType())->trans($this->translator));
        yield IntegerField::new('entityId', 'audit.field.entity_id');
        yield TextField::new('entityLabel', 'audit.field.entity_label');
        yield TextField::new('locale', 'audit.field.locale');
        yield TextField::new('field', 'audit.field.field');
        yield TextareaField::new('beforeText', 'audit.field.before')->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e ? '' : $this->value($e->getBefore()));
        yield TextareaField::new('afterText', 'audit.field.after')->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e ? '' : $this->value($e->getAfter()));
        yield IntegerField::new('userId', 'audit.field.user_id');
        yield IntegerField::new('tokenId', 'audit.field.token');
        yield TextField::new('changesetId', 'audit.field.changeset')->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')->renderAsHtml()
            ->formatValue(fn ($v, ?AdminAuditEntry $e): string => null === $e?->getChangesetId() ? '' : \sprintf('<a href="%s">#%d</a>', htmlspecialchars($this->changesetUrl($e->getChangesetId()), \ENT_QUOTES), $e->getChangesetId()));
        yield TextField::new('ip', 'audit.field.ip');
    }

    /** Посилання на сторінку пакета (E15 PR-4) */
    private function changesetUrl(int $id): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(ChangesetCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();
    }

    /** Значення для показу (AdminAuditEntry::format) з «так/ні» мовою адмінки */
    private function value(mixed $value): string
    {
        return AdminAuditEntry::format($value, true, $this->translator->trans('audit.value.yes', [], 'admin'), $this->translator->trans('audit.value.no', [], 'admin'));
    }
}

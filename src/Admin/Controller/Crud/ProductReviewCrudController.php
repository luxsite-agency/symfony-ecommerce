<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Security\Entity\User;
use App\Core\Shared\Translation\ExceptionMessage;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Відгуки на товари (рішення власника 13.10.2026): список з фільтрами (стан, оцінка, «купив», демо), масове схвалення й
 * відхилення, сторінка перегляду зі схвалити/відхилити й відповіддю магазину (POST з CSRF), видалення. Текст покупця не
 * редагується (створення й редагування вимкнені): правити чужі слова не можна, лише схвалити чи відхилити. Рішення й
 * агрегат оцінок — ProductReviews.
 *
 * @extends AbstractCrudController<ProductReview>
 */
final class ProductReviewCrudController extends AbstractCrudController
{
    /** Бейджі стану — за назвою case: status — enumType з TranslatableInterface, EasyAdmin бере варіанти й підписи з enum. */
    private const array STATUS_BADGES = ['Pending' => 'warning', 'Approved' => 'success', 'Rejected' => 'secondary'];

    public function __construct(
        private readonly ProductReviews $reviews,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ProductReview::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('review.label.singular')
            ->setEntityLabelInPlural('review.label.plural')
            ->setSearchFields(['text', 'customer.email', 'customer.name', 'product.translations.name'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->overrideTemplate('crud/detail', 'admin/review/detail.html.twig')
            ->setHelp(Crud::PAGE_INDEX, 'review.help.index');
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('batchApprove', 'review.action.approve', 'fa fa-check')->linkToCrudAction('batchApprove')->createAsBatchAction();
        $reject = Action::new('batchReject', 'review.action.reject', 'fa fa-ban')->linkToCrudAction('batchReject')->createAsBatchAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT)
            ->addBatchAction($approve)
            ->addBatchAction($reject);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // підписи варіантів — ключі enum.review_status.* у домені admin (ChoiceFilter типово перекладає в EasyAdminBundle)
            ->add(ChoiceFilter::new('status', 'review.filter.status')->setChoices(self::statusChoices())->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(NumericFilter::new('rating', 'review.filter.rating'))
            ->add(BooleanFilter::new('verifiedPurchase', 'review.filter.verified_purchase'))
            ->add(BooleanFilter::new('demo', 'review.filter.demo'));
    }

    public function configureFields(string $pageName): iterable
    {
        $locale = $this->defaultLocale;

        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'review.field.created_at');
        yield ChoiceField::new('status', 'review.field.status')->renderAsBadges(self::STATUS_BADGES);
        yield TextField::new('product', 'review.field.product')->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?ProductReview $r): string => $r?->getProduct()->getTranslation($locale)?->getName() ?? ($r?->getProduct()->getDefaultVariant()->getSku() ?? ''));
        yield IntegerField::new('rating', 'review.field.rating');
        yield TextField::new('customer.name', 'review.field.customer');
        yield TextField::new('customer.email', 'review.field.email')->onlyOnDetail();
        yield BooleanField::new('verifiedPurchase', 'review.field.verified_purchase')->renderAsSwitch(false);
        yield BooleanField::new('demo', 'review.field.demo')->renderAsSwitch(false)->hideOnIndex();
        yield TextField::new('locale', 'review.field.locale')->hideOnIndex();
        yield TextareaField::new('text', 'review.field.text')->setMaxLength(160)->onlyOnIndex();
        yield TextareaField::new('text', 'review.field.text')->onlyOnDetail();
        yield TextareaField::new('replyText', 'review.field.reply_text')->onlyOnDetail();
        yield TextField::new('replyBy', 'review.field.reply_by')->onlyOnDetail();
        yield DateTimeField::new('repliedAt', 'review.field.replied_at')->onlyOnDetail();
        yield TextField::new('moderatedBy', 'review.field.moderated_by')->onlyOnDetail();
        yield DateTimeField::new('moderatedAt', 'review.field.moderated_at')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'review.field.updated_at')->onlyOnDetail();
    }

    /** Список: товар і покупець одним запитом (без N+1). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // свої аліаси: пошук EasyAdmin за customer.name / product.translations.name сам додає join з аліасами customer і product,
        // і однакові давали 500 «'customer' is already defined» на будь-якому пошуку у відгуках
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('review_customer')->join('entity.customer', 'review_customer')
            ->addSelect('review_product')->join('entity.product', 'review_product');
    }

    /** @param BatchActionDto<ProductReview> $batchActionDto */
    #[AdminRoute(path: '/batch-approve', name: 'batch_approve')]
    public function batchApprove(BatchActionDto $batchActionDto): Response
    {
        return $this->moderateBatch($batchActionDto, ReviewStatus::Approved, 'review.flash.batch_approved');
    }

    /** @param BatchActionDto<ProductReview> $batchActionDto */
    #[AdminRoute(path: '/batch-reject', name: 'batch_reject')]
    public function batchReject(BatchActionDto $batchActionDto): Response
    {
        return $this->moderateBatch($batchActionDto, ReviewStatus::Rejected, 'review.flash.batch_rejected');
    }

    /**
     * Сторінка перегляду: POST decision=approve|reject|reply (+ reply — текст відповіді, порожній прибирає) з CSRF review-<id>.
     *
     * @param AdminContext<ProductReview> $context
     */
    #[AdminRoute(path: '/{entityId}/moderate', name: 'moderate', options: ['methods' => ['POST']])]
    public function moderate(AdminContext $context, Request $request): Response
    {
        $review = $context->getEntity()->getInstance();
        if (!$review instanceof ProductReview) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('review-'.$review->getId(), (string) $request->request->get('token', ''))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            switch ((string) $request->request->get('decision', '')) {
                case 'approve':
                    $this->reviews->approve($review, $user);
                    $this->addFlash('success', t('review.flash.approved', [], 'admin'));
                    break;
                case 'reject':
                    $this->reviews->reject($review, $user);
                    $this->addFlash('success', t('review.flash.rejected', [], 'admin'));
                    break;
                case 'reply':
                    $this->reviews->reply($review, (string) $request->request->get('reply', ''), $user);
                    $this->addFlash('success', t($review->hasReply() ? 'review.flash.reply_saved' : 'review.flash.reply_removed', [], 'admin'));
                    break;
                default:
                    throw new TranslatableInvalidArgumentException('Невідоме рішення', 'review.error.unknown_decision');
            }
        } catch (InvalidArgumentException $e) {
            // флеші EasyAdmin рендеряться |raw: лише свої тексти, екрановано
            $this->addFlash('danger', htmlspecialchars(ExceptionMessage::trans($e, $this->translator), \ENT_QUOTES));
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->setController(self::class)->setAction(Action::DETAIL)->setEntityId($review->getId())->generateUrl());
    }

    /** Видалення — через ProductReviews, щоб перерахувати агрегат оцінок товару. */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->reviews->delete($entityInstance);
    }

    /** @param BatchActionDto<ProductReview> $batchActionDto */
    private function moderateBatch(BatchActionDto $batchActionDto, ReviewStatus $status, string $messageKey): Response
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.$batchActionDto->getName().'-'.$batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен масової дії');
        }
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $count = 0;
        foreach ($batchActionDto->getEntityIds() as $id) {
            $review = $this->em->find(ProductReview::class, $id);
            if (null === $review || $review->getStatus() === $status) {
                continue;
            }
            ReviewStatus::Approved === $status ? $this->reviews->approve($review, $user) : $this->reviews->reject($review, $user);
            ++$count;
        }
        $this->addFlash('success', t($messageKey, ['count' => $count], 'admin'));

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    /** @return array<string, string> ключ підпису (enum.review_status.*) => код стану, для фільтра */
    private static function statusChoices(): array
    {
        return array_combine(array_map(static fn (ReviewStatus $s): string => $s->transKey(), ReviewStatus::cases()), array_map(static fn (ReviewStatus $s): string => $s->value, ReviewStatus::cases()));
    }
}

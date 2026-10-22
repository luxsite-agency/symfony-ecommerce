<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Changeset\ChangesetPresenter;
use App\Admin\Security\ChangesetVoter;
use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetReverter;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetConflict;
use App\Core\Changeset\Exception\ChangesetNotApplicable;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Changeset\Exception\RiskConfirmationRequired;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Пакети змін в адмінці (E15 PR-4, дизайн B pr4-ui.md): список своїх (адміністратор — усіх), сторінка пакета з diff,
 * ризиками й попередженнями, кнопки «Застосувати» (з чекбоксом підтвердження для ризикових — 422 без нього), «Відхилити»,
 * «Відкотити» (створює пакет-відкат, нічого не застосовує). Усе — POST з CSRF; права — ChangesetVoter. Конфлікт при
 * застосуванні — та сама сторінка зі статусом 409 і таблицею «хто/коли». Пакети створює AI через REST/MCP або CLI;
 * тут їх лише розглядають. Тексти — ключі changeset.* домену admin (мова адмінки).
 *
 * @extends AbstractCrudController<Changeset>
 */
final class ChangesetCrudController extends AbstractCrudController
{
    private const array STATUS_BADGES = ['proposed' => 'info', 'applied' => 'success', 'rejected' => 'secondary', 'expired' => 'light'];

    public function __construct(
        private readonly ChangesetApplier $applier,
        private readonly ChangesetReverter $reverter,
        private readonly ChangesetPresenter $presenter,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.ai_changeset_ttl%')] private readonly string $ttl,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Changeset::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('changeset.label.singular')
            ->setEntityLabelInPlural('changeset.label.plural')
            ->setSearchFields(['title', 'author'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->overrideTemplate('crud/detail', 'admin/changeset/detail.html.twig')
            ->setHelp(Crud::PAGE_INDEX, t('changeset.help.index', ['ttl' => $this->ttl], 'admin'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $apply = Action::new('apply', 'changeset.action.apply', 'fa fa-check')->linkToCrudAction('apply')->setCssClass('btn btn-primary')
            ->displayIf(fn (Changeset $c): bool => $c->isProposed() && $this->isGranted(ChangesetVoter::APPLY, $c));
        $reject = Action::new('reject', 'changeset.action.reject', 'fa fa-xmark')->linkToCrudAction('reject')->setCssClass('btn btn-outline-danger')
            ->displayIf(fn (Changeset $c): bool => $c->isProposed() && $this->isGranted(ChangesetVoter::REJECT, $c));
        $revert = Action::new('revert', 'changeset.action.revert', 'fa fa-rotate-left')->linkToCrudAction('revert')->setCssClass('btn btn-outline-secondary')
            ->displayIf(fn (Changeset $c): bool => $this->isGranted(ChangesetVoter::REVERT, $c));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_DETAIL, $reject)
            ->add(Crud::PAGE_DETAIL, $apply)
            ->add(Crud::PAGE_DETAIL, $revert);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            // setTranslatableChoices: назви enum-ів (TranslatableInterface) мовою адмінки; setChoices перекладав би в домені EasyAdminBundle
            ->add(ChoiceFilter::new('status', 'changeset.column.status')->setTranslatableChoices(ChangesetStatus::cases()))
            ->add(ChoiceFilter::new('source', 'changeset.column.source')->setTranslatableChoices(AuditSource::cases()))
            ->add(DateTimeFilter::new('createdAt', 'changeset.column.created_at'));
        if ($this->isGranted(UserRole::Admin->value)) {
            $filters->add(EntityFilter::new('user', 'changeset.column.author'));
        }

        return $filters;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'changeset.column.id');
        yield TextField::new('title', 'changeset.column.title')->setTemplatePath('@EasyAdmin/crud/field/text.html.twig');
        yield TextField::new('effectiveStatusLabel', 'changeset.column.status')->setSortable(false)->renderAsHtml()->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(fn ($v, ?Changeset $c): string => null === $c ? '' : $this->statusBadge($c));
        yield TextField::new('sourceLabel', 'changeset.column.source')->setSortable(false)->formatValue(fn ($v, ?Changeset $c): string => null === $c ? '' : $c->getSource()->trans($this->translator));
        yield TextField::new('author', 'changeset.column.author');
        yield TextField::new('operationsLabel', 'changeset.column.operations')->setSortable(false)->renderAsHtml()->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(fn ($v, ?Changeset $c): string => null === $c ? '' : \count($c->getOperations()).($c->hasPriceOperations() ? \sprintf(' <span class="badge badge-secondary" title="%s">%s</span>', htmlspecialchars($this->translator->trans('changeset.badge.prices_title', [], 'admin'), \ENT_QUOTES), htmlspecialchars($this->translator->trans('changeset.badge.prices', [], 'admin'), \ENT_QUOTES)) : ''));
        yield TextField::new('riskLabel', 'changeset.column.risks')->setSortable(false)->renderAsHtml()->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(fn ($v, ?Changeset $c): string => null === $c ? '' : implode(' ', array_map(fn (string $code): string => \sprintf('<span class="badge badge-warning">%s</span>', htmlspecialchars($this->presenter->riskLabel($code), \ENT_QUOTES)), $c->getRiskFlags())));
        yield DateTimeField::new('createdAt', 'changeset.column.created_at');
        yield TextField::new('whenLabel', 'changeset.column.when')->setSortable(false)->formatValue(fn ($v, ?Changeset $c): string => null === $c ? '' : $this->when($c));
    }

    /** Менеджер — лише свої пакети; адмін — усі (разом з пакетами видалених користувачів). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->isGranted(UserRole::Admin->value)) {
            $qb->andWhere('entity.user = :me')->setParameter('me', $this->currentUser());
        }

        return $qb;
    }

    /** Чужий пакет — 403 ще на сторінці (voter VIEW), не лише в списку. */
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $changeset = $context->getEntity()->getInstance();
        if ($changeset instanceof Changeset) {
            $this->denyAccessUnlessGranted(ChangesetVoter::VIEW, $changeset);
        }

        return parent::detail($context);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $changeset = $this->getContext()?->getEntity()->getInstance();
            if ($changeset instanceof Changeset) {
                foreach ($this->detailParameters($changeset) as $key => $value) {
                    $responseParameters->set($key, $value);
                }
            }
        }

        return $responseParameters;
    }

    /**
     * Застосувати: POST token (CSRF) + risk_confirmed=1 для ризикового. 303 на сторінку; конфлікт — сторінка з 409.
     *
     * @param AdminContext<Changeset> $context
     */
    #[AdminRoute(path: '/{entityId}/apply', name: 'apply', options: ['methods' => ['POST']])]
    public function apply(AdminContext $context, Request $request): Response
    {
        $changeset = $this->entity($context, $request, 'apply');
        $this->denyAccessUnlessGranted(ChangesetVoter::APPLY, $changeset);
        $confirmed = '1' === (string) $request->request->get('risk_confirmed', '');
        // не-proposed (повторний POST, прострочення) — хай аплаєр скаже «уже застосовано», а не «поставте позначку»
        if ($changeset->isProposed() && $changeset->hasRisk() && !$confirmed) {
            // не входити в транзакцію даремно; аплаєр перевірить ще раз
            $this->addFlash('danger', t('changeset.flash.confirm_risk', [], 'admin'));

            return $this->renderDetail($changeset, ['risk_invalid' => true], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->applier->apply($changeset, $this->currentUser(), $confirmed, $this->ip($request));
        } catch (RiskConfirmationRequired) {
            $this->addFlash('danger', t('changeset.flash.confirm_risk', [], 'admin'));

            return $this->renderDetail($changeset, ['risk_invalid' => true], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ChangesetNotApplicable $e) {
            $this->addFlash('warning', $this->notApplicableText($changeset, $e));

            return $this->redirectToDetail($changeset);
        } catch (ChangesetConflict $e) {
            $changeset = $this->reload($changeset);
            $this->addFlash('danger', t('changeset.flash.conflict', [], 'admin'));

            return $this->renderDetail($changeset, ['conflicts' => $this->presenter->conflicts($e->issues, $changeset)], Response::HTTP_CONFLICT);
        }
        $count = \count($changeset->getOperations());
        // flash EasyAdmin виводиться |trans|raw (рядок — у домені messages, тому завжди t(…, 'admin')); URL екрановано тут
        $this->addFlash('success', t('changeset.flash.applied', ['count' => $count, 'url' => htmlspecialchars($this->auditUrl($changeset), \ENT_QUOTES), 'mail' => $changeset->hasPriceOperations() ? 'yes' : 'no'], 'admin'));

        return $this->redirectToDetail($changeset);
    }

    /** @param AdminContext<Changeset> $context */
    #[AdminRoute(path: '/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function reject(AdminContext $context, Request $request): Response
    {
        $changeset = $this->entity($context, $request, 'reject');
        $this->denyAccessUnlessGranted(ChangesetVoter::REJECT, $changeset);
        try {
            $this->applier->reject($changeset, $this->currentUser(), $this->ip($request));
            $this->addFlash('success', t('changeset.flash.rejected', [], 'admin'));
        } catch (ChangesetNotApplicable $e) {
            $this->addFlash('warning', $this->notApplicableText($changeset, $e));
        }

        return $this->redirectToDetail($changeset);
    }

    /**
     * Відкотити = створити пакет-відкат (proposed, revert_of) і перейти на нього; нічого не застосовується.
     *
     * @param AdminContext<Changeset> $context
     */
    #[AdminRoute(path: '/{entityId}/revert', name: 'revert', options: ['methods' => ['POST']])]
    public function revert(AdminContext $context, Request $request): Response
    {
        $changeset = $this->entity($context, $request, 'revert');
        $this->denyAccessUnlessGranted(ChangesetVoter::REVERT, $changeset);
        try {
            $revert = $this->reverter->propose($changeset, ChangesetAuthor::of($this->currentUser(), AuditSource::AdminUi, null, $this->ip($request)));
        } catch (ChangesetNotApplicable $e) {
            $this->addFlash('warning', $this->notApplicableText($changeset, $e));

            return $this->redirectToDetail($changeset);
        } catch (ChangesetValidationFailed $e) {
            $changeset = $this->reload($changeset);
            $this->addFlash('danger', t('changeset.flash.revert_failed', [], 'admin'));

            return $this->renderDetail($changeset, ['revert_errors' => $this->presenter->conflicts($e->issues, $changeset)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('changeset.flash.revert_created', ['id' => (string) $revert->getId()], 'admin'));

        return $this->redirectToDetail($revert);
    }

    /** @return array<string, mixed> */
    private function detailParameters(Changeset $changeset): array
    {
        $view = $this->presenter->present($changeset);
        $revertedBy = $this->revertedBy($changeset);
        $now = new DateTimeImmutable();

        return [
            'cs' => $changeset,
            'view' => $view,
            'status' => $changeset->effectiveStatus($now),
            'status_badge' => self::STATUS_BADGES[$changeset->effectiveStatus($now)->value],
            'hours_left' => $changeset->isProposed($now) ? max(0, (int) floor(($changeset->getExpiresAt()->getTimestamp() - $now->getTimestamp()) / 3600)) : null,
            'can_apply' => $changeset->isProposed($now) && $this->isGranted(ChangesetVoter::APPLY, $changeset),
            'can_reject' => $changeset->isProposed($now) && $this->isGranted(ChangesetVoter::REJECT, $changeset),
            'can_revert' => $this->isGranted(ChangesetVoter::REVERT, $changeset) && null === $revertedBy,
            'reverted_by' => $revertedBy,
            'csrf' => ['apply' => 'changeset-apply-'.$changeset->getId(), 'reject' => 'changeset-reject-'.$changeset->getId(), 'revert' => 'changeset-revert-'.$changeset->getId()],
            'urls' => [
                'apply' => $this->actionUrl($changeset, 'apply'),
                'reject' => $this->actionUrl($changeset, 'reject'),
                'revert' => $this->actionUrl($changeset, 'revert'),
                'audit' => $this->auditUrl($changeset),
                'revert_of' => null === $changeset->getRevertOf() ? null : $this->detailUrl($changeset->getRevertOf()),
                'reverted_by' => null === $revertedBy ? null : $this->detailUrl($revertedBy),
                'index' => $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl(),
            ],
            'entity_urls' => $this->entityUrls($view['rows']),
            'conflicts' => [],
            'revert_errors' => [],
            'risk_invalid' => false,
        ];
    }

    /**
     * Сторінка пакета зі статусом 422/409 (той самий шаблон, що й detail, без EasyAdmin-контексту сутності).
     *
     * @param array<string, mixed> $extra
     */
    private function renderDetail(Changeset $changeset, array $extra, int $status): Response
    {
        $parameters = $this->detailParameters($changeset) + $extra;
        foreach ($extra as $key => $value) {
            $parameters[$key] = $value;
        }

        return $this->render('admin/changeset/page.html.twig', $parameters, new Response('', $status));
    }

    /** @param AdminContext<Changeset> $context */
    private function entity(AdminContext $context, Request $request, string $action): Changeset
    {
        $changeset = $context->getEntity()->getInstance();
        if (!$changeset instanceof Changeset) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('changeset-'.$action.'-'.$changeset->getId(), (string) $request->request->get('token', ''))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }

        return $changeset;
    }

    private function reload(Changeset $changeset): Changeset
    {
        // після відкату транзакції аплаєр очищає EntityManager — перечитати
        $fresh = $this->container->get('doctrine')->getRepository(Changeset::class)->find($changeset->getId());

        return $fresh instanceof Changeset ? $fresh : $changeset;
    }

    private function revertedBy(Changeset $changeset): ?Changeset
    {
        if (ChangesetStatus::Applied !== $changeset->getStatus()) {
            return null;
        }
        $found = $this->container->get('doctrine')->getRepository(Changeset::class)->findOneBy(['revertOf' => $changeset, 'status' => ChangesetStatus::Applied]);

        return $found instanceof Changeset ? $found : null;
    }

    /**
     * Посилання на форми сутностей з diff: варіант і поріг — форма товару.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string|null> індекс операції → URL
     */
    private function entityUrls(array $rows): array
    {
        $generator = $this->container->get(AdminUrlGeneratorInterface::class);
        $out = [];
        foreach ($rows as $row) {
            [$controller, $id] = match ($row['type']) {
                'product', 'product_variant', 'price_tier' => [ProductCrudController::class, $row['product_id']],
                'category' => [CategoryCrudController::class, $row['id']],
                'page' => [PageCrudController::class, $row['id']],
                'post' => [PostCrudController::class, $row['id']],
                default => [null, null],
            };
            $out[(int) $row['index']] = null === $controller || null === $id ? null : $generator->unsetAll()->setController($controller)->setAction(Action::EDIT)->setEntityId($id)->generateUrl();
        }

        return $out;
    }

    private function actionUrl(Changeset $changeset, string $action): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction($action)->setEntityId($changeset->getId())->generateUrl();
    }

    private function detailUrl(Changeset $changeset): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::DETAIL)->setEntityId($changeset->getId())->generateUrl();
    }

    private function auditUrl(Changeset $changeset): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(AdminAuditCrudController::class)->setAction(Action::INDEX)
            ->set('filters[changesetId][comparison]', '=')->set('filters[changesetId][value]', (string) $changeset->getId())->generateUrl();
    }

    private function redirectToDetail(Changeset $changeset): Response
    {
        return $this->redirect($this->detailUrl($changeset), Response::HTTP_SEE_OTHER);
    }

    private function ip(Request $request): ?string
    {
        $ip = $request->getClientIp();

        return null === $ip ? null : IpUtils::anonymize($ip);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    private function statusBadge(Changeset $changeset): string
    {
        $status = $changeset->effectiveStatus();

        return \sprintf('<span class="badge badge-%s">%s</span>', self::STATUS_BADGES[$status->value], htmlspecialchars($status->trans($this->translator), \ENT_QUOTES));
    }

    private function when(Changeset $changeset): string
    {
        return match ($changeset->effectiveStatus()) {
            ChangesetStatus::Proposed => $this->translator->trans('changeset.when.until', ['date' => $changeset->getExpiresAt()->format('d.m.Y H:i')], 'admin'),
            ChangesetStatus::Applied => $changeset->getAppliedAt()?->format('d.m.Y H:i').' '.$changeset->getAppliedByName(),
            ChangesetStatus::Rejected => $changeset->getRejectedAt()?->format('d.m.Y H:i').' '.$changeset->getRejectedByName(),
            ChangesetStatus::Expired => '—',
        };
    }

    /** Flash виводиться |raw — імена користувачів екрануються. */
    private function notApplicableText(Changeset $changeset, ChangesetNotApplicable $e): TranslatableInterface
    {
        return match ($e->status) {
            ChangesetStatus::Applied => t('changeset.flash.already_applied', ['name' => htmlspecialchars($changeset->getAppliedByName() ?? '—', \ENT_QUOTES), 'date' => $changeset->getAppliedAt()?->format('d.m.Y H:i') ?? ''], 'admin'),
            ChangesetStatus::Rejected => t('changeset.flash.already_rejected', ['name' => htmlspecialchars($changeset->getRejectedByName() ?? '—', \ENT_QUOTES), 'date' => $changeset->getRejectedAt()?->format('d.m.Y H:i') ?? ''], 'admin'),
            ChangesetStatus::Expired => t('changeset.flash.expired', [], 'admin'),
            ChangesetStatus::Proposed => t('changeset.flash.not_applicable', ['id' => (string) $e->changesetId, 'status' => $e->status], 'admin'),
        };
    }
}

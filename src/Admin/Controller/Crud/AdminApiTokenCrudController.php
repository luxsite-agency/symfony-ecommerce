<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Form\NewApiTokenType;
use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Translation\ExceptionMessage;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API-токени (E15 PR-2): менеджер бачить і створює лише свої (рішення власника 11.10, п. 4), адміністратор бачить усі
 * й відкликає будь-який. Значення токена показується один раз після створення (flash), далі — лише префікс.
 * Стандартні new/edit/delete вимкнені: створення — власна форма (issue), відкликання — POST з CSRF (revoke), видалення
 * рядків нема (історія «хто коли мав доступ»). Відкликання — посилання з рядка на сторінку підтвердження, далі POST з CSRF.
 *
 * @extends AbstractCrudController<AdminApiToken>
 */
final class AdminApiTokenCrudController extends AbstractCrudController
{
    /** Ключ сесії зі щойно виданим токеном: читається й стирається при першому показі списку. */
    public const string SESSION_PLAIN = 'api_token_plain';
    private const string REVOKE_CSRF = 'api-token-revoke';

    public function __construct(private readonly AdminApiTokens $tokens, private readonly TranslatorInterface $translator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return AdminApiToken::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('api_token.label.singular')
            ->setEntityLabelInPlural('api_token.label.plural')
            ->setSearchFields(['name', 'prefix'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/api_token/index.html.twig')
            ->setHelp(Crud::PAGE_INDEX, t('api_token.help.index', ['days' => (string) $this->tokens->maxDays()], 'admin'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $issue = Action::new('issue', 'api_token.action.issue', 'fa fa-plus')
            ->linkToCrudAction('issue')
            ->createAsGlobalAction()
            ->asPrimaryAction();
        $revoke = Action::new('revoke', 'api_token.action.revoke', 'fa fa-ban')
            ->linkToCrudAction('revoke')
            ->displayIf(static fn (AdminApiToken $t): bool => !$t->isRevoked());

        return $actions
            // DETAIL теж вимкнено (рев'ю C #73 L2): інакше менеджер відкрив би чужий рядок за URL
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $issue)
            ->add(Crud::PAGE_INDEX, $revoke);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters->add(ChoiceFilter::new('scopes', 'api_token.filter.scope')->setChoices(ApiScope::choices())
            ->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'));
        if ($this->isGranted(UserRole::Admin->value)) {
            $filters->add(EntityFilter::new('user', 'api_token.field.owner'));
        }

        return $filters;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'api_token.field.name');
        yield TextField::new('maskedToken', 'api_token.field.token')->setHelp('api_token.help.token');
        yield TextField::new('scopesText', 'api_token.field.scopes');
        if ($this->isGranted(UserRole::Admin->value)) {
            yield TextField::new('ownerName', 'api_token.field.owner');
        }
        // стан — active | expired | revoked з сутності, підпис мовою адмінки
        yield TextField::new('status', 'api_token.field.status')->setSortable(false)
            ->formatValue(fn ($v, ?AdminApiToken $t): string => null === $t ? '' : $this->translator->trans('api_token.status.'.$t->getStatus(), [], 'admin'));
        yield DateTimeField::new('createdAt', 'api_token.field.created_at');
        yield DateTimeField::new('expiresAt', 'api_token.field.expires_at');
        yield DateTimeField::new('lastUsedAt', 'api_token.field.last_used_at');
        yield TextField::new('lastUsedIp', 'api_token.field.last_used_ip')->setHelp('api_token.help.last_used_ip');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $session = $this->container->get('request_stack')->getSession();
            $plain = $session->get(self::SESSION_PLAIN);
            $session->remove(self::SESSION_PLAIN);
            $responseParameters->set('token_plain', \is_string($plain) ? $plain : null);
        }

        return $responseParameters;
    }

    /** Менеджер — лише свої токени; адмін — усі. */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->isGranted(UserRole::Admin->value)) {
            $qb->andWhere('entity.user = :me')->setParameter('me', $this->currentUser());
        }

        return $qb;
    }

    #[AdminRoute(path: '/issue', name: 'issue', options: ['methods' => ['GET', 'POST']])]
    public function issue(Request $request): Response
    {
        $user = $this->currentUser();
        $form = $this->createForm(NewApiTokenType::class, null, ['scopes' => $this->tokens->allowedScopes($user), 'max_days' => $this->tokens->maxDays()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, scopes: list<ApiScope>, days: int} $data */
            $data = $form->getData();
            try {
                $issued = $this->tokens->issue($user, $data['name'], $data['scopes'], $data['days'], $user);
                // значення — один раз, через сесію (на сервері), на сторінці списку; у БД і логах його нема
                $request->getSession()->set(self::SESSION_PLAIN, $issued->plain);
                // назва — від користувача, а флеші EasyAdmin — |raw
                $this->addFlash('success', t('api_token.flash.issued', ['name' => htmlspecialchars($issued->token->getName(), \ENT_QUOTES)], 'admin'));

                return $this->redirect($this->indexUrl());
            } catch (InvalidArgumentException $e) {
                $this->addFlash('danger', htmlspecialchars(ExceptionMessage::trans($e, $this->translator), \ENT_QUOTES)); // AdminApiTokens::issue — форма ці межі вже перевірила
            }
        }

        return $this->render('admin/api_token/issue.html.twig', ['form' => $form, 'max_days' => $this->tokens->maxDays(), 'index_url' => $this->indexUrl()]);
    }

    /** GET ?entityId= — сторінка підтвердження; POST (id + CSRF) — відкликання. Чужий токен — лише ROLE_ADMIN. */
    #[AdminRoute(path: '/revoke', name: 'revoke', options: ['methods' => ['GET', 'POST']])]
    public function revoke(Request $request): Response
    {
        $id = (int) ($request->isMethod('POST') ? $request->request->get('id', 0) : $request->query->get('entityId', 0));
        $token = $this->container->get('doctrine')->getRepository(AdminApiToken::class)->find($id);
        if (!$token instanceof AdminApiToken) {
            throw $this->createNotFoundException('Токен не знайдено');
        }
        $user = $this->currentUser();
        if ($token->getUser() !== $user && !$this->isGranted(UserRole::Admin->value)) {
            throw new AccessDeniedException('Чужий токен відкликає лише адміністратор');
        }
        if (!$request->isMethod('POST')) {
            return $this->render('admin/api_token/revoke.html.twig', ['token' => $token, 'index_url' => $this->indexUrl()]);
        }
        if (!$this->isCsrfTokenValid(self::REVOKE_CSRF, (string) $request->request->get('token', ''))) {
            $this->addFlash('danger', t('api_token.flash.csrf_invalid', [], 'admin'));

            return $this->redirect($this->indexUrl());
        }
        $this->tokens->revoke($token, $user);
        $this->addFlash('success', t('api_token.flash.revoked', ['name' => htmlspecialchars($token->getName(), \ENT_QUOTES)], 'admin'));

        return $this->redirect($this->indexUrl());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    private function indexUrl(): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
    }
}

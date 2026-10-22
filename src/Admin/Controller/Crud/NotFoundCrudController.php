<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Seo\Entity\NotFoundEntry;
use App\Core\Seo\NotFoundLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

/**
 * Журнал 404 сайту (епік E3): адреси, які віддали 404 і не мають правила, — зі зверненнями й останнім referer. З рядка —
 * «Створити редирект» (форма з підставленою адресою); правило знімає адресу з журналу. Обсяг обмежено автоматично.
 *
 * @extends AbstractCrudController<NotFoundEntry>
 */
final class NotFoundCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly NotFoundLog $log,
        #[Autowire('%app.seo_not_found_limit%')] private readonly int $limit,
        #[Autowire('%app.seo_not_found_days%')] private readonly int $days,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return NotFoundEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('not_found.label.singular')
            ->setEntityLabelInPlural('not_found.label.plural')
            ->setSearchFields(['path', 'referer'])
            ->setDefaultSort(['hits' => 'DESC', 'lastHitAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            // числа рядками: ICU відформатувало б 10000 як «10 000»
            ->setHelp(Crud::PAGE_INDEX, t('not_found.help.index', ['limit' => (string) $this->limit, 'days' => (string) $this->days], 'admin'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $redirect = Action::new('createRedirect', 'not_found.action.create_redirect', 'fa fa-share')->linkToCrudAction('createRedirect');
        $clear = Action::new('clear', 'not_found.action.clear', 'fa fa-trash')->linkToCrudAction('clear')->createAsGlobalAction()->setCssClass('btn btn-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $redirect)
            ->add(Crud::PAGE_INDEX, $clear);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('path', 'not_found.field.path');
        yield IntegerField::new('hits', 'not_found.field.hits');
        yield DateTimeField::new('lastHitAt', 'not_found.field.last_hit_at');
        yield DateTimeField::new('firstSeenAt', 'not_found.field.first_seen_at')->hideOnIndex();
        yield TextField::new('referer', 'not_found.field.referer');
    }

    /** @param AdminContext<NotFoundEntry> $context */
    #[AdminRoute(path: '/{entityId}/create-redirect', name: 'create_redirect')]
    public function createRedirect(AdminContext $context): Response
    {
        $entry = $context->getEntity()->getInstance();
        if (!$entry instanceof NotFoundEntry) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()
            ->setController(RedirectCrudController::class)->setAction(Action::NEW)->set('source', $entry->getPath())->generateUrl());
    }

    #[AdminRoute(path: '/clear', name: 'clear', options: ['methods' => ['GET', 'POST']])]
    public function clear(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('seo-not-found-clear', (string) $request->request->get('token', ''))) {
                $this->addFlash('danger', t('not_found.flash.csrf_invalid', [], 'admin'));
            } else {
                $this->addFlash('success', t('not_found.flash.cleared', ['count' => $this->log->clear()], 'admin'));
            }

            return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        return $this->render('admin/seo/not_found_clear.html.twig');
    }
}

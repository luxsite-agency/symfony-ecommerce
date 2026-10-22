<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function Symfony\Component\Translation\t;

/**
 * Точний збіг з одним записом (номер замовлення, SKU чи штрихкод) — одразу картка, без списку. Повернутись до списку
 * результатів — посилання у флеші (?exact=0 вимикає перехід). Лише перша сторінка списку без фільтрів.
 */
#[AsEventListener]
final class ExactMatchListener
{
    public function __construct(
        private readonly SmartSearchRepository $search,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
    ) {
    }

    public function __invoke(BeforeCrudActionEvent $event): void
    {
        $context = $event->getAdminContext();
        $request = $context?->getRequest();
        $crud = $context?->getCrud();
        if (null === $request || null === $crud || Action::INDEX !== $crud->getCurrentAction()
            || '0' === $request->query->get('exact') || $request->query->has('filters') || $request->query->getInt('page', 1) > 1) {
            return;
        }
        $query = trim($request->query->getString('query'));
        $searcher = '' === $query ? null : $this->search->searcher((string) $crud->getEntityFqcn());
        $id = $searcher?->exactId($query);
        if (null === $id) {
            return;
        }
        $all = $this->urls->setController((string) $crud->getControllerFqcn())->setAction(Action::INDEX)->set('query', $query)->set('exact', '0')->generateUrl();
        $session = $this->requests->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('info', t('search.exact_opened', ['query' => htmlspecialchars($query, \ENT_QUOTES), 'url' => htmlspecialchars($all, \ENT_QUOTES)], 'admin'));
        $event->setResponse(new RedirectResponse($this->urls->setController((string) $crud->getControllerFqcn())->setAction(Action::DETAIL)->setEntityId($id)->unset('query')->generateUrl()));
    }
}

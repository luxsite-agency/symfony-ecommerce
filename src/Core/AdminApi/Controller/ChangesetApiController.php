<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Controller;

use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Core\AdminApi\Auth\ApiWriteLimiter;
use App\Core\AdminApi\Changeset\ChangesetListing;
use App\Core\AdminApi\Changeset\ChangesetScopes;
use App\Core\AdminApi\Changeset\ChangesetView;
use App\Core\AdminApi\Changeset\PriceOpsQuota;
use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Exception\ChangesetException;
use App\Core\Security\Entity\User;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Пакети змін через REST (E15 PR-4): створити (dry-run + збереження), лише перевірити, прочитати свої. Застосувати через
 * API не можна — лише людина на сторінці пакета в адмінці (рішення власника 11.10, п. 1); відповідь містить її url.
 * Скоуп залежить від операцій (ChangesetScopes): цінові — prices:write, решта — content:write (обидва, якщо пакет змішаний);
 * читання — будь-який скоуп. Пакет бачить його автор і ROLE_ADMIN, інші — 404 (ChangesetListing). Ліміт цінових операцій
 * на токен за добу (застосовані + у чинних proposed, PriceOpsQuota) — 429; спроби створення на токен за хвилину — ApiWriteLimiter (429). Ті самі сервіси — під інструментами MCP (PR-5).
 */
#[Route('/api/admin/v1/changesets', host: '%admin_host%', format: 'json', name: 'admin_api_changesets_')]
final class ChangesetApiController extends AbstractController
{
    public function __construct(
        private readonly ChangesetPlanner $planner,
        private readonly ChangesetView $view,
        private readonly ChangesetListing $listing,
        private readonly ApiTokenContext $tokens,
        private readonly PriceOpsQuota $quota,
        private readonly ApiWriteLimiter $writes,
    ) {
    }

    /** Dry-run і збереження як proposed: 201 з diff, попередженнями, ризиками й url сторінки. */
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('scope:content:write') or is_granted('scope:prices:write')"))]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->body($request);
        $this->denyUnlessScopesFor($data);
        $token = $this->tokens->current() ?? throw new AccessDeniedException('Лише з API-токеном');
        $retryAfter = $this->writes->consume($token);
        if (null !== $retryAfter) {
            return $this->json(['error' => ApiWriteLimiter::ERROR, 'error_description' => 'Забагато спроб створити пакет, спробуйте пізніше'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
        }
        $limited = $this->quota->exceeded($data, (int) $token->getId());
        if (null !== $limited) {
            return $this->json($limited, Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) PriceOpsQuota::RETRY_AFTER]);
        }
        $ip = $request->getClientIp();
        try {
            $changeset = $this->planner->propose($data, ChangesetAuthor::of($user, AuditSource::AiApi, $token->getId(), null === $ip ? null : IpUtils::anonymize($ip)));
        } catch (ChangesetException $e) {
            return $this->json($e->payload(), $e->statusCode);
        }

        return $this->json($this->view->full($changeset), Response::HTTP_CREATED, ['Location' => $this->view->url($changeset)]);
    }

    /** Лише перевірка: план без збереження (200 і з помилками — у тілі errors; клієнт бачить усе одразу). */
    #[Route('/dry-run', name: 'dry_run', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('scope:content:write') or is_granted('scope:prices:write')"))]
    public function dryRun(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $this->denyUnlessScopesFor($data);
        try {
            $plan = $this->planner->dryRun($data);
        } catch (ChangesetException $e) {
            return $this->json($e->payload(), $e->statusCode);
        }

        return $this->json(ChangesetView::plan($plan));
    }

    /** Мої пакети (адміністратор з ?all=1 — усі), найновіші перші; status — фільтр за effectiveStatus. */
    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('scope:catalog:read') or is_granted('scope:content:write') or is_granted('scope:prices:write')"))]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $result = $this->listing->list(
            $user,
            $request->query->getBoolean('all'),
            ChangesetStatus::tryFrom((string) $request->query->get('status', '')),
            $request->query->getInt('limit', 20),
            $request->query->getInt('page', 1),
        );

        return $this->json(['items' => array_map($this->view->summary(...), $result['items'])] + $result);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('scope:catalog:read') or is_granted('scope:content:write') or is_granted('scope:prices:write')"))]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // чужий пакет — 404, а не 403: не підтверджувати існування
        $changeset = $this->listing->find($id, $user) ?? throw $this->createNotFoundException('Пакет не знайдено');

        return $this->json($this->view->full($changeset));
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode((string) $request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Тіло запиту — не JSON: '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Очікується об\'єкт {"title", "operations"}');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function denyUnlessScopesFor(array $data): void
    {
        foreach (ChangesetScopes::required($data) as $scope) {
            $this->denyAccessUnlessGranted('scope:'.$scope->value);
        }
    }
}

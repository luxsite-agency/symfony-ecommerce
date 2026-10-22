<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Controller;

use App\Core\Integration\Crm\CrmConnectorRegistry;
use App\Core\Integration\Crm\Exception\WebhookRejectedException;
use App\Core\Integration\Crm\OrderStatusChange;
use App\Core\Integration\Crm\StockLevel;
use App\Core\Integration\Crm\StockLevelApplier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Спільний вебхук CRM (ADR Rev 3): перевірку токена й розбір робить активний конектор, застосування — StockLevelApplier.
 * Ідемпотентний: повторний той самий payload нічого не змінює. Статуси замовлень лише логуються до Ф2.
 * Коли активна CRM вебхука не приймає (none, інша CRM) — 404 без журналу, як неіснуюча адреса.
 */
final class CrmWebhookController
{
    public function __construct(
        private readonly CrmConnectorRegistry $connectors,
        private readonly StockLevelApplier $applier,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.crm_webhook_ip')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/webhooks/crm/stock', name: 'crm_webhook_stock', methods: ['POST'], stateless: true)]
    public function stock(Request $request): JsonResponse
    {
        if (!$this->connectors->webhookEnabled()) {
            throw new NotFoundHttpException();
        }
        // ліміт з IP лише на невдалі спроби (консиліум безпеки 10.10, п. 8; рев'ю #56, п. 2): перебір токена, а масове
        // оновлення залишків з KeyCRM (сотні POST за хвилину з однієї адреси) з правильним токеном проходить без 429
        $limiter = $this->limiter->create($request->getClientIp() ?? 'anon');
        $state = $limiter->consume(0); // лише перевірка: consume(0) завжди accepted, дивимось на залишок
        if ($state->getRemainingTokens() <= 0) {
            $this->logger->warning('CRM webhook: перевищено ліміт невдалих спроб з IP');

            return new JsonResponse(['error' => 'Забагато запитів'], 429, ['Retry-After' => (string) max(1, $state->getRetryAfter()->getTimestamp() - time())]);
        }
        $connector = $this->connectors->active();
        try {
            $events = $connector->handleWebhook($request);
        } catch (WebhookRejectedException $e) {
            if (403 === $e->statusCode) {
                $limiter->consume();
            }
            $this->logger->warning('CRM webhook відхилено', ['system' => $connector->getSystem(), 'reason' => $e->getMessage()]);

            return new JsonResponse(['error' => $e->getMessage()], $e->statusCode);
        }

        $levels = [];
        $orderEvents = 0;
        foreach ($events as $event) {
            if ($event instanceof StockLevel) {
                $levels[] = $event;
            } elseif ($event instanceof OrderStatusChange) {
                ++$orderEvents;
                $this->logger->info('CRM webhook: статус замовлення (обробка — Ф2)', ['externalOrderId' => $event->externalOrderId, 'status' => $event->status]);
            }
        }
        $report = $this->applier->apply($connector->getSystem(), $levels);

        return new JsonResponse([
            'system' => $connector->getSystem(),
            'received' => \count($levels),
            'changed' => $report->changed,
            'unchanged' => $report->unchanged,
            'clamped' => $report->clamped,
            'unknown' => $report->unknownSkus,
            'orderEvents' => $orderEvents,
        ]);
    }
}

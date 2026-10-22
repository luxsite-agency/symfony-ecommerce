<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Журнал запитів до /api/admin і /mcp (E15 PR-6, канал admin_api; на проді — var/log/admin_api.log, 366 днів): HTTP-метод,
 * маршрут REST, метод JSON-RPC, інструмент MCP, id токена, статус, тривалість. Свідомо БЕЗ query, тіла, аргументів
 * інструмента, відповіді, ідентифікаторів ресурсів і IP: у запитах — промпти й дані каталогу, відповідь — те, що бачить
 * модель. Хто/звідки — за token_id (security_audit і admin_api_token.last_used_ip). nginx для цих шляхів теж пише лише
 * метод, вид і статус (log_format shop_api). Тут же — Cache-Control: no-store на кожну відповідь API (вміст з токеном
 * не осідає в проксі й кешах).
 */
#[WithMonologChannel('admin_api')]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
final class ApiAccessLog
{
    /** Атрибути запиту, які виставляє McpController після розбору JSON-RPC. */
    public const string ATTR_RPC = '_api_rpc';
    public const string ATTR_TOOL = '_api_tool';

    public function __construct(private readonly ApiTokenContext $context, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ApiPaths::matches($request)) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store');
        $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
        $route = $request->attributes->get('_route');
        $rpc = $request->attributes->get(self::ATTR_RPC);
        $tool = $request->attributes->get(self::ATTR_TOOL);
        $this->logger->info('api.request', [
            'method' => $request->getMethod(),
            'route' => \is_string($route) ? $route : null,
            'rpc' => \is_string($rpc) ? $rpc : null,
            'tool' => \is_string($tool) ? $tool : null,
            'token_id' => $this->context->current()?->getId(),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}

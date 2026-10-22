<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Захисні заголовки з коду, не з проксі (консиліум безпеки 10.10, п. 5): nginx магазину їх теж ставить, але Cloudflare,
 * traefik чи Caddy клієнта на це покладатись не можна. Адмінка (admin_host) не вбудовується нікуди (клікджекінг схвалення
 * оптовика, переходів замовлень, створення користувача), сайт — лише сам у себе (checkout). nosniff всюди.
 * Referrer-Policy і Permissions-Policy — лише коли їх ще нема: кабінет (AccountResponseHeaders, strict-origin) і «дякуємо»
 * (same-origin) ставлять свої. frame-ancestors обмежує, хто може вбудувати НАС, а не що ми вбудовуємо: noscript-iframe
 * GTM і піксель Meta лишаються робочими (frame-src не задається). Пріоритет -200: після WebDebugToolbarListener (-128),
 * який у dev/test з вимкненим CSP профайлера прибирає заголовки Content-Security-Policy з відповіді.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -200)]
final class SecurityResponseHeaders
{
    /** Без payment=(): при онлайн-оплаті (LiqPay, Google Pay) директива payment=(self "https://…") потрібна сайту (ADR). */
    public const string PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()';

    public function __construct(#[Autowire('%admin_host%')] private readonly string $adminHost)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $admin = 0 === strcasecmp($event->getRequest()->getHost(), $this->adminHost);
        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', $admin ? 'DENY' : 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $frameAncestors = 'frame-ancestors '.($admin ? "'none'" : "'self'");
        $csp = trim((string) $headers->get('Content-Security-Policy', ''));
        if ('' === $csp) {
            $headers->set('Content-Security-Policy', $frameAncestors);
        } elseif (!str_contains($csp, 'frame-ancestors')) {
            $headers->set('Content-Security-Policy', rtrim($csp, '; ').'; '.$frameAncestors);
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if (!$headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        }
    }
}

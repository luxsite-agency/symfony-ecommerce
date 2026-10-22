<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

use App\Core\Analytics\Ecommerce\MetaPixelEventMapper;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Події dataLayer, зібрані під час серверного рендеру: шаблон викликає analytics_push(), провайдер перед </body>
 * віддає їх JSON-ом у data-атрибуті + невеликий JS, що пушить у window.dataLayer (без взаємодії користувача).
 * Ecommerce-події, що мають відповідник у Meta, отримують ключ meta_pixel {event, event_id, params} у момент
 * push/queue: з нього шлють і вбудований піксель (за згоди), і теги Meta в GTM — дані ті самі, що в GA4.
 */
final class DataLayerCollector
{
    private const string SESSION_KEY = 'analytics.queued';

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly MetaPixelEventMapper $metaPixel,
    ) {
    }

    /** @param array<string, mixed> $event */
    public function push(array $event): void
    {
        if (isset($event['event'])) {
            $this->events[] = $this->withMetaPixel($event);
        }
    }

    /**
     * Подія після POST → redirect (add_to_cart, remove_from_cart): у сесію, вийде на наступній сторінці.
     *
     * @param array<string, mixed> $event
     */
    public function queue(array $event): void
    {
        if (!isset($event['event'])) {
            return;
        }
        $event = $this->withMetaPixel($event);
        $session = $this->session();
        if (null === $session) {
            $this->push($event);

            return;
        }
        /** @var list<array<string, mixed>> $queued */
        $queued = $session->get(self::SESSION_KEY, []);
        $queued[] = $event;
        $session->set(self::SESSION_KEY, \array_slice($queued, -20));
    }

    /**
     * Події цього запиту + відкладені з попереднього (черга спорожняється).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // читати чергу лише з уже існуючої сесії: has() на нестартованій сесії стартує її → session-cookie кожному
        // відвідувачу/боту і Cache-Control: private на всіх сторінках (консиліум PR #6, low)
        $request = $this->requests->getMainRequest();
        $session = null !== $request && ($request->hasPreviousSession() || ($request->hasSession() && $request->getSession()->isStarted())) ? $this->session() : null;
        $queued = [];
        if (null !== $session && $session->has(self::SESSION_KEY)) {
            /** @var list<array<string, mixed>> $queued */
            $queued = $session->get(self::SESSION_KEY, []);
            $session->remove(self::SESSION_KEY);
        }

        return [...$queued, ...$this->events];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function withMetaPixel(array $event): array
    {
        if (!isset($event['meta_pixel']) && null !== ($meta = $this->metaPixel->map($event))) {
            $event['meta_pixel'] = $meta;
        }

        return $event;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requests->getMainRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}

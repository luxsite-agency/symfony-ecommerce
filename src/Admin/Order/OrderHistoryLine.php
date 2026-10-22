<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Order;

use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\OrderStatus;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Запис історії замовлення людськими словами для картки в адмінці (аудит UX A4): «Створено покупцем на сайті», «Нове → Підтверджене»,
 * хто — «менеджер ivan@…», «система», «демо-дані». У БД лишаються коди (transition, actor, comment), тут лише показ мовою адмінки
 * (ключі order_history.* і enum.order_status.* у домені admin). Для створення comment — код джерела (PlaceOrder, PriceRequestService),
 * його не показуємо як коментар.
 */
final readonly class OrderHistoryLine
{
    /** Відомі автори з коду (не email менеджера) => ключ order_history.actor.* */
    private const array ACTORS = ['customer' => 'customer', 'system' => 'system', 'demo-seed' => 'demo_seed', 'admin' => 'admin'];

    public function __construct(public DateTimeImmutable $at, public string $event, public ?string $toStatus, public string $actor, public ?string $comment)
    {
    }

    public static function of(OrderStatusHistory $h, TranslatorInterface $translator): self
    {
        if ('place' === $h->getTransition()) {
            $source = OrderSource::tryFrom((string) $h->getComment());
            $event = $translator->trans('order_history.created.'.($source->value ?? 'default'), [], 'admin');

            return new self($h->getAt(), $event.':', self::status($h->getToStatus(), $translator), self::actor($h->getActor(), $translator), null);
        }
        $from = null === $h->getFromStatus() ? '—' : self::status($h->getFromStatus(), $translator);

        return new self($h->getAt(), $from.' →', self::status($h->getToStatus(), $translator), self::actor($h->getActor(), $translator), $h->getComment());
    }

    private static function status(string $code, TranslatorInterface $translator): string
    {
        return OrderStatus::tryFrom($code)?->trans($translator) ?? $code;
    }

    private static function actor(string $actor, TranslatorInterface $translator): string
    {
        return match (true) {
            isset(self::ACTORS[$actor]) => $translator->trans('order_history.actor.'.self::ACTORS[$actor], [], 'admin'),
            str_contains($actor, '@') => $translator->trans('order_history.actor.manager', ['email' => $actor], 'admin'),
            default => $actor,
        };
    }
}

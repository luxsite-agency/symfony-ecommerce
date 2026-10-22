<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Ecommerce;

use Symfony\Component\HttpFoundation\RequestStack;

/** purchase пушиться один раз на замовлення: номери вже відправлених — у сесії (перезавантаження «дякуємо» не дублює). */
final class PurchaseDedup
{
    private const string SESSION_KEY = 'analytics.purchased';
    private const int KEEP = 20;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** true — уперше для цього номера (і його запам'ятовано); false — уже відправляли. */
    public function firstTime(string $orderNumber): bool
    {
        $session = $this->requests->getSession();
        /** @var list<string> $sent */
        $sent = $session->get(self::SESSION_KEY, []);
        if (\in_array($orderNumber, $sent, true)) {
            return false;
        }
        $sent[] = $orderNumber;
        $session->set(self::SESSION_KEY, \array_slice($sent, -self::KEEP));

        return true;
    }
}

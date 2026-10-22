<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart;

use App\Core\Cart\Entity\CartItem;
use App\Core\Shared\Money\Money;

/** Розрахований рядок кошика: ціна за одиницю на цю кількість і сума рядка. */
final readonly class CartLine
{
    public function __construct(
        public CartItem $item,
        public Money $unitPrice,
        public Money $lineTotal,
        public ?string $problem = null,
        /** ціна до акцій, коли є знижка (перекреслена в кошику); null — знижки нема */
        public ?Money $listPrice = null,
        /** @var list<array{id: ?int, name: string}> акції, що спрацювали */
        public array $promotions = [],
        /** @var array<string, mixed>|null снепшот опцій, перерахований за поточним каталогом (підписи, ціни); якщо вибір став невалідним — останній збережений (з problem invalid_options); null — без опцій */
        public ?array $optionsSnapshot = null,
        /** роздрібна ціна одиниці для покупця з групою, коли вона інша («роздріб» поруч); null — та сама або роздріб */
        public ?Money $retailPrice = null,
    ) {
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;

/**
 * Вхід PlaceOrder для обох потоків (ADR D8): швидке замовлення (ім'я + телефон, доставка/оплата = manager_contact,
 * один рядок) і повний checkout (усі рядки кошика, доставка, оплата, коментар). idempotencyKey — з форми.
 */
final readonly class PlaceOrderRequest
{
    /**
     * lines.options — снепшот рядка кошика (ADR Rev 9), без нього — значення за замовчуванням; lines.card_price — ціна
     * картки в копійках, яку бачив покупець швидкого замовлення (звіряється з Pricing::cardQuote: форма без JS ціни
     * за кількістю не показує). expectedPrice — PriceFingerprint сторінки checkout; null — без звірки (демо, внутрішні виклики).
     * customerId — покупець із security-токена (лише з нього, не з форми); PlaceOrder сам читає покупця і перечитує його групу в транзакції.
     *
     * @param list<array{variant: ProductVariant, quantity: string, options?: array<string, mixed>|null, card_price?: int|null}> $lines
     */
    public function __construct(
        public OrderSource $source,
        public string $locale,
        public string $customerName,
        public string $phone,
        public array $lines,
        public ?string $email = null,
        public ?string $comment = null,
        public ShippingMethod $shippingMethod = ShippingMethod::ManagerContact,
        public ?string $shippingCity = null,
        public ?string $shippingAddress = null,
        public PaymentMethod $paymentMethod = PaymentMethod::ManagerContact,
        public ?string $idempotencyKey = null,
        public ?string $ip = null,
        public ?ShippingPoint $shippingPoint = null,
        public ?string $expectedPrice = null,
        public ?int $customerId = null,
    ) {
    }
}

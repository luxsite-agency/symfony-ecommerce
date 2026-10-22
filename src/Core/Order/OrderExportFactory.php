<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Integration\Crm\OrderExport;
use App\Core\Order\Entity\Order;

/**
 * Order → OrderExport для CrmConnectorInterface::pushOrder (контракт §1): рядки по sku з ціною сайту. Дочірні рядки
 * компонентів опцій ідуть теж — з ціною 0 (вона вже в батьківському рядку), щоб CRM як master залишків (D7) списала
 * резерв їхніх SKU; сума товарів у CRM дорівнює платежу (консиліум 07.10.2026 п. 2, рев'ю PR #15 п. 5).
 */
final class OrderExportFactory
{
    public function fromOrder(Order $order): OrderExport
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = ['sku' => $item->getSku(), 'quantity' => $item->getQuantity(), 'unitPriceMinor' => $item->getUnitPrice()->amount, 'lineTotalMinor' => $item->getLineTotal()->amount];
        }

        /** @var list<array{sku: string, quantity: string, unitPriceMinor: int, lineTotalMinor: int}> $items */
        return new OrderExport(
            siteOrderNumber: $order->getNumber(),
            currency: $order->getCurrency(),
            items: $items,
            buyer: ['name' => $order->getCustomerName(), 'phone' => $order->getPhone(), 'email' => $order->getEmail()],
            shipping: ['method' => $order->getShippingMethod()->value, 'city' => $order->getShippingCity(), 'address' => $order->getShippingAddress(), 'np_city_ref' => $order->getShippingPoint()?->cityRef, 'np_warehouse_ref' => $order->getShippingPoint()?->warehouseRef, 'np_warehouse_number' => $order->getShippingPoint()?->warehouseNumber],
            payment: ['method' => $order->getPaymentMethod()->value, 'status' => $order->getPaymentStatus()->value],
            comment: $order->getComment(),
            itemsTotalMinor: $order->getItemsTotal()->amount,
            shippingMinor: $order->getShipping()->amount,
            totalMinor: $order->getTotal()->amount,
        );
    }
}

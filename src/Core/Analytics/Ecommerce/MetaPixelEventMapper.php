<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Ecommerce;

/**
 * Стандартні події Meta Pixel з GA4-подій EcommerceEventBuilder (одне джерело правди для dataLayer, пікселя й GTM):
 * view_item → ViewContent, add_to_cart → AddToCart, begin_checkout → InitiateCheckout, purchase → Purchase.
 * content_ids = item_id = SKU варіанта (як g:id у фідах meta-<locale>.xml), content_type = product, contents
 * [{id, quantity, item_price}], value/currency — ті самі, що в GA4 (ціни з фасаду Pricing).
 * event_id: Purchase — номер замовлення (детермінований, Conversions API зможе дедуплікувати з бекенду); решта —
 * унікальний id події, виданий на сервері в момент push/queue (там же колись піде й серверна подія CAPI).
 */
final class MetaPixelEventMapper
{
    /** GA4 → Meta. Решта (view_item_list, view_cart, remove_from_cart…) стандартних відповідників для каталогу не має. */
    public const array EVENTS = [
        'view_item' => 'ViewContent',
        'add_to_cart' => 'AddToCart',
        'begin_checkout' => 'InitiateCheckout',
        'purchase' => 'Purchase',
    ];

    /**
     * @param array<string, mixed> $event подія dataLayer ({event, ecommerce: {currency, value, items}})
     *
     * @return array{event: string, event_id: string, params: array<string, mixed>}|null null — подія не для Meta
     */
    public function map(array $event): ?array
    {
        $name = self::EVENTS[\is_string($event['event'] ?? null) ? $event['event'] : ''] ?? null;
        $ecommerce = $event['ecommerce'] ?? null;
        if (null === $name || !\is_array($ecommerce) || !\is_array($ecommerce['items'] ?? null)) {
            return null;
        }

        $ids = [];
        $contents = [];
        $numItems = 0;
        foreach ($ecommerce['items'] as $item) {
            if (!\is_array($item) || !\is_string($item['item_id'] ?? null) || '' === $item['item_id']) {
                continue;
            }
            [$quantity, $itemPrice] = self::quantityAndPrice($item);
            $content = ['id' => $item['item_id'], 'quantity' => $quantity];
            if (null !== $itemPrice) {
                $content['item_price'] = $itemPrice;
            }
            $ids[] = $item['item_id'];
            $contents[] = $content;
            $numItems += $quantity;
        }
        if ([] === $contents) {
            return null;
        }

        $params = [
            'content_ids' => array_values(array_unique($ids)),
            'content_type' => 'product',
            'contents' => $contents,
            'currency' => \is_string($ecommerce['currency'] ?? null) ? $ecommerce['currency'] : 'UAH',
        ];
        if (isset($ecommerce['value']) && is_numeric($ecommerce['value'])) {
            $params['value'] = (float) $ecommerce['value'];
        }
        if ('InitiateCheckout' === $name || 'Purchase' === $name) {
            $params['num_items'] = $numItems;
        }

        $eventId = null;
        if ('Purchase' === $name) {
            $orderNumber = $ecommerce['transaction_id'] ?? null;
            if (!\is_string($orderNumber) || '' === $orderNumber) {
                return null; // Purchase без номера замовлення не дедуплікується — не шлемо
            }
            $params['value'] ??= 0.0; // Meta вимагає value + currency для Purchase (товари «ціна за запитом» — 0)
            $params['order_id'] = $orderNumber;
            $eventId = $orderNumber;
        }

        return [
            'event' => $name,
            'event_id' => $eventId ?? strtolower($name).'.'.bin2hex(random_bytes(8)),
            'params' => $params,
        ];
    }

    /**
     * Meta чекає цілу кількість. Дробову (метри, кг) віддаємо одним рядком: quantity 1, item_price = сума рядка —
     * value = Σ item_price × quantity збігається з GA4.
     *
     * @param array<mixed> $item
     *
     * @return array{int, float|null}
     */
    private static function quantityAndPrice(array $item): array
    {
        $quantity = is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 1.0;
        $price = is_numeric($item['price'] ?? null) ? (float) $item['price'] : null;
        if ($quantity > 0 && floor($quantity) === $quantity) {
            return [(int) $quantity, $price];
        }

        return [1, null === $price ? null : round($price * $quantity, 2)];
    }
}

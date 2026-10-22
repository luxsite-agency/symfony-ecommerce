<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Ecommerce;

use App\Core\Catalog\Entity\AttributeValueTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Entity\VariantAxisValue;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Shared\Money\Money;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Payload подій GA4 ecommerce для dataLayer: view_item_list, select_item, view_item, add_to_cart, remove_from_cart,
 * view_cart, begin_checkout, add_shipping_info, add_payment_info, purchase, generate_lead.
 * item_id = sku, ціни у грошових одиницях (minor/100) як число, кількість — число. Кошика й checkout ще нема (Ф2):
 * білдер готовий, виклики додаються там. purchase — один раз на замовлення (PurchaseDedup).
 */
final class EcommerceEventBuilder
{
    public function __construct(#[Autowire('%app.default_locale%')] private readonly string $defaultLocale)
    {
    }

    /** @return array<string, mixed> */
    public function item(ProductVariant $variant, float|int|string $quantity = 1, ?int $index = null, ?string $listName = null, ?string $locale = null, ?Money $unitPrice = null, ?Money $listPrice = null): array
    {
        $product = $variant->getProduct();
        $translation = $product->getTranslation($locale ?? $this->defaultLocale) ?? $product->getTranslation($this->defaultLocale);
        $category = $product->getMainCategory();
        $categoryTranslation = null === $category ? null : ($category->getTranslation($locale ?? $this->defaultLocale) ?? $category->getTranslation($this->defaultLocale));
        // підпис значення осі мовою запиту → мовою за замовчуванням → код (не «перший переклад»: на uk-сторінці йшло en)
        $axes = array_map(function (VariantAxisValue $a) use ($locale): string {
            $value = $a->getValue();
            foreach ([$locale ?? $this->defaultLocale, $this->defaultLocale] as $l) {
                $t = $value->getTranslation($l);
                if ($t instanceof AttributeValueTranslation && '' !== $t->getValue()) {
                    return $t->getValue();
                }
            }

            return $value->getCode();
        }, $variant->getAxisValues()->toArray());

        $item = [
            'item_id' => $variant->getSku(),
            'item_name' => null !== $translation && '' !== $translation->getName() ? $translation->getName() : $variant->getSku(),
            'price' => self::money(($unitPrice ?? $variant->getPrice())->amount),
            'quantity' => self::quantity($quantity),
            'currency' => $variant->getCurrency(),
        ];
        if (PriceMode::OnRequest === $product->getPriceMode()) {
            unset($item['price']); // «ціна за запитом» — службова 0 у варіанті, не ціна (seo-tech-notes п. 24)
        } elseif (null !== $unitPrice && null !== $listPrice && $listPrice->greaterThan($unitPrice)) {
            // GA4 discount — знижка акції за одиницю (Ф3); пороги кількості знижкою не вважаються
            $item['discount'] = self::money($listPrice->subtract($unitPrice)->amount);
        }
        if (null !== $product->getBrand()) {
            $item['item_brand'] = $product->getBrand()->getName();
        }
        if (null !== $categoryTranslation && '' !== $categoryTranslation->getName()) {
            $item['item_category'] = $categoryTranslation->getName();
        }
        if ([] !== $axes) {
            $item['item_variant'] = implode(' / ', $axes);
        }
        if (null !== $index) {
            $item['index'] = $index;
        }
        if (null !== $listName) {
            $item['item_list_name'] = $listName;
        }

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function viewItemList(array $items, string $listName, ?string $listId = null): array
    {
        return $this->event('view_item_list', ['item_list_name' => $listName, 'item_list_id' => $listId ?? self::slugify($listName), 'items' => $items]);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    public function selectItem(array $item, string $listName): array
    {
        return $this->event('select_item', ['item_list_name' => $listName, 'item_list_id' => self::slugify($listName), 'items' => [$item]]);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function viewItem(array $items): array
    {
        return $this->event('view_item', self::withValue($items));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function addToCart(array $items): array
    {
        return $this->event('add_to_cart', self::withValue($items));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function removeFromCart(array $items): array
    {
        return $this->event('remove_from_cart', self::withValue($items));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function viewCart(array $items): array
    {
        return $this->event('view_cart', self::withValue($items));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function beginCheckout(array $items, ?string $coupon = null): array
    {
        return $this->event('begin_checkout', self::withValue($items) + array_filter(['coupon' => $coupon]));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function addShippingInfo(array $items, string $shippingTier): array
    {
        return $this->event('add_shipping_info', self::withValue($items) + ['shipping_tier' => $shippingTier]);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function addPaymentInfo(array $items, string $paymentType): array
    {
        return $this->event('add_payment_info', self::withValue($items) + ['payment_type' => $paymentType]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param int                        $shippingMinor у мінорних одиницях
     * @param int                        $taxMinor      у мінорних одиницях
     *
     * @return array<string, mixed>
     */
    public function purchase(string $transactionId, array $items, int $shippingMinor = 0, int $taxMinor = 0, ?string $coupon = null): array
    {
        $payload = self::withValue($items) + [
            'transaction_id' => $transactionId,
            'shipping' => self::money($shippingMinor),
            'tax' => self::money($taxMinor),
        ] + array_filter(['coupon' => $coupon]);

        return $this->event('purchase', $payload);
    }

    /**
     * Заявка / quick order: value необов'язковий (сума кошика або оцінка ліда).
     *
     * @return array<string, mixed>
     */
    public function generateLead(?int $valueMinor = null, string $currency = 'UAH', ?string $leadSource = null): array
    {
        $payload = ['currency' => $currency] + (null === $valueMinor ? [] : ['value' => self::money($valueMinor)]) + array_filter(['lead_source' => $leadSource]);

        return ['event' => 'generate_lead'] + $payload;
    }

    /**
     * @param array<string, mixed> $ecommerce
     *
     * @return array<string, mixed>
     */
    private function event(string $name, array $ecommerce): array
    {
        return ['event' => $name, 'ecommerce' => $ecommerce];
    }

    /**
     * value = Σ price × quantity; currency — з першого item.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private static function withValue(array $items): array
    {
        $total = '0';
        $currency = null;
        $priced = false;
        foreach ($items as $item) {
            $priced = $priced || isset($item['price']);
            $total = bcadd($total, bcmul((string) ($item['price'] ?? 0), (string) ($item['quantity'] ?? 1), 4), 4);
            $currency ??= $item['currency'] ?? null;
        }
        // жодної ціни (лише «за запитом») — без value, а не value 0
        $event = ['currency' => $currency ?? 'UAH'] + ($priced ? ['value' => (float) bcadd($total, '0', 2)] : []);

        return $event + ['items' => $items];
    }

    private static function money(int $minor): float
    {
        return (float) bcdiv((string) $minor, '100', 2);
    }

    private static function quantity(float|int|string $quantity): float|int
    {
        $normalized = (float) str_replace(',', '.', (string) $quantity);

        return floor($normalized) === $normalized ? (int) $normalized : $normalized;
    }

    private static function slugify(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($text)), '_') ?: 'list';
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Export;

use App\Core\B2b\Repository\CompanyMemberRepository;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Repository\CustomerAddressRepository;
use App\Core\Order\Entity\Order;
use App\Core\Order\Repository\OrderRepository;

/** Усі дані покупця одним масивом для JSON (ст. 8 Закону про захист ПД): профіль, адреси, згоди, замовлення зі снепшотами, компанія. */
final class CustomerDataExport
{
    public function __construct(
        private readonly CustomerAddressRepository $addresses,
        private readonly OrderRepository $orders,
        private readonly Consents $consents,
        private readonly CompanyMemberRepository $members,
        private readonly ProductReviewRepository $reviews,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Customer $customer): array
    {
        $member = $this->members->findByCustomer($customer);

        return [
            'exported_at' => date(\DATE_ATOM),
            'profile' => [
                'email' => $customer->getEmail(),
                'email_verified_at' => $customer->getEmailVerifiedAt()?->format(\DATE_ATOM),
                'name' => $customer->getName(),
                'phone' => $customer->getPhone(),
                'locale' => $customer->getLocale(),
                'customer_group' => $customer->getCustomerGroup()?->getName(),
                'created_at' => $customer->getCreatedAt()->format(\DATE_ATOM),
                'last_login_at' => $customer->getLastLoginAt()?->format(\DATE_ATOM),
            ],
            // рев'ю #74, L4: прив'язка Google — теж дані покупця (sub — ідентифікатор особи в Google)
            'google' => !$customer->isGoogleLinked() ? null : [
                'email' => $customer->getGoogleEmail(),
                'sub' => $customer->getGoogleSub(),
                'linked_at' => $customer->getGoogleLinkedAt()?->format(\DATE_ATOM),
            ],
            'company' => null === $member ? null : [
                'name' => $member->getCompany()->getName(),
                'tax_id' => $member->getCompany()->getTaxId(),
                'vat_payer' => $member->getCompany()->isVatPayer(),
                'contact_name' => $member->getCompany()->getContactName(),
                'contact_phone' => $member->getCompany()->getContactPhone(),
            ],
            'addresses' => array_map(static fn ($a): array => [
                'label' => $a->getLabel(),
                'recipient_name' => $a->getRecipientName(),
                'recipient_phone' => $a->getRecipientPhone(),
                'shipping_method' => $a->getShippingMethod()->value,
                'city' => $a->getCity(),
                'address' => $a->getAddress(),
                'shipping_point' => $a->getShippingPoint()?->toArray(),
                'is_default' => $a->isDefault(),
            ], $this->addresses->findForCustomer($customer)),
            'consents' => array_map(static fn ($c): array => [
                'purpose' => $c->getPurpose()->value,
                'granted' => $c->isGranted(),
                'policy_version' => $c->getPolicyVersion(),
                'policy_hash' => $c->getPolicyHash(),
                'source' => $c->getSource(),
                'occurred_at' => $c->getOccurredAt()->format(\DATE_ATOM),
            ], $this->consents->history($customer)),
            'orders' => array_map(self::order(...), $this->orders->findForCustomer($customer, 1, 10_000)),
            'reviews' => array_map(static fn ($r): array => [
                'product_sku' => $r->getProduct()->getDefaultVariant()->getSku(),
                'rating' => $r->getRating(),
                'text' => $r->getText(),
                'status' => $r->getStatus()->value,
                'created_at' => $r->getCreatedAt()->format(\DATE_ATOM),
                'updated_at' => $r->getUpdatedAt()->format(\DATE_ATOM),
            ], $this->reviews->findForCustomer($customer)),
        ];
    }

    /** @return array<string, mixed> */
    private static function order(Order $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            if (null === $item->getParent()) {
                $items[] = ['sku' => $item->getSku(), 'name' => $item->getName(), 'quantity' => $item->getQuantity(), 'unit_price' => $item->getUnitPrice()->toDecimalString(), 'line_total' => $item->getLineTotal()->toDecimalString()];
            }
        }

        return [
            'number' => $order->getNumber(),
            'placed_at' => $order->getPlacedAt()->format(\DATE_ATOM),
            'status' => $order->getStatus(),
            'payment_status' => $order->getPaymentStatus()->value,
            'customer_name' => $order->getCustomerName(),
            'phone' => $order->getPhone(),
            'email' => $order->getEmail(),
            'comment' => $order->getComment(),
            'shipping' => ['method' => $order->getShippingMethod()->value, 'city' => $order->getShippingCity(), 'address' => $order->getShippingAddress(), 'point' => $order->getShippingPoint()?->toArray()],
            'payment_method' => $order->getPaymentMethod()->value,
            'currency' => $order->getCurrency(),
            'total' => $order->getTotal()->toDecimalString(),
            'claimed_at' => $order->getClaimedAt()?->format(\DATE_ATOM),
            'items' => $items,
        ];
    }
}

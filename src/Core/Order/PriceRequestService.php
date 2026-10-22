<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * «Запитати ціну» для товару з ціною за запитом (бриф Сокіл 77): лід іде тим самим каналом, що й замовлення — Order із
 * джерелом price_request, рядок з ціною 0 без резерву залишку, коментар покупця; у тій самій транзакції outbox:
 * PushOrderToCrm (менеджер бачить лід у KeyCRM) і лист магазину. Листа покупцю нема — йому ще нема чого підтверджувати.
 */
final class PriceRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderNumberGenerator $numbers,
        private readonly MessageBusInterface $bus,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    /** @throws OrderValidationException */
    public function submit(ProductVariant $variant, string $name, string $phone, ?string $comment, string $locale, ?string $ip = null): Order
    {
        $errors = [];
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            $errors['customerName'] = 'Вкажіть ім\'я';
        }
        try {
            $phone = PhoneNormalizer::normalize($phone);
        } catch (InvalidArgumentException $e) {
            $errors['phone'] = $e->getMessage();
        }
        $product = $variant->getProduct();
        if (!$variant->isActive() || !$product->isActive() || PriceMode::OnRequest !== $product->getPriceMode()) {
            $errors['lines'] = 'Для цього товару ціна вже відома — оформіть замовлення';
        }
        $comment = null === $comment ? null : mb_substr(trim($comment), 0, 1000);
        if ([] !== $errors) {
            throw new OrderValidationException($errors);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $order = new Order($this->numbers->next(), OrderSource::PriceRequest, $locale, $name, $phone, $variant->getCurrency(), ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
            $order->setComment('Запит ціни'.(null !== $comment && '' !== $comment ? ': '.$comment : ''));
            $order->setIp($ip);
            $translation = $product->getTranslation($locale) ?? $product->getTranslation($this->defaultLocale);
            $order->addItem(new OrderItem($order, $variant, null !== $translation ? $translation->getName() : $variant->getSku(), Money::zero($variant->getCurrency()), '1'));
            $order->recalculateTotals();
            $order->addHistory(new OrderStatusHistory($order, null, $order->getStatus(), 'place', 'customer', OrderSource::PriceRequest->value));
            $this->em->persist($order);
            $this->em->flush();

            $this->bus->dispatch(new PushOrderToCrm((int) $order->getId()));
            $this->bus->dispatch(new SendShopOrderEmail((int) $order->getId()));
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $order;
    }
}

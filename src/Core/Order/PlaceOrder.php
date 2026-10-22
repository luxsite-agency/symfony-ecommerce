<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Catalog\Option\OptionConfiguration;
use App\Core\Catalog\QuantityRules;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Exception\OrderAlreadyPlacedException;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Exception\OutOfStockException;
use App\Core\Order\Exception\PriceChangedException;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\Minimum\OrderMinimum;
use App\Core\Pricing\LineQuote;
use App\Core\Pricing\PriceFingerprint;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Один application service на швидке замовлення і checkout (ADR D8). У транзакції: номер (атомарний upsert), резерв
 * залишку атомарним UPDATE, Order + снепшоти рядків, історія; там само диспатч PushOrderToCrm і двох листів
 * у Messenger doctrine-транспорт (той самий connection = outbox, D7). Повторний submit з тим самим idempotencyKey
 * (і тим самим телефоном) повертає вже створене замовлення; одночасний — OrderAlreadyPlacedException з токеном сторінки «дякуємо».
 * Ціна фіксується на момент оформлення, але не мовчки: перерахована в транзакції ціна звіряється з тією, яку бачив
 * покупець (PriceFingerprint checkout або ціна картки швидкого замовлення); розбіжність — PriceChangedException, нічого не створено.
 * Покупець (E13): customerId з токена → Customer читається тут для валідації, а в транзакції перечитується (refresh):
 * якщо групу змінили між сторінкою й submit, рядки перераховуються за поточною і звіряються з відбитком, як і ціни.
 * Група (лише активного) іде в кожен Pricing::line/cardQuote, замовлення отримує customer_id і снепшот коду групи.
 * Security-токен цей сервіс не читає (фід, CLI, Messenger).
 */
final class PlaceOrder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderNumberGenerator $numbers,
        private readonly StockReservation $stock,
        private readonly Pricing $pricing,
        private readonly MessageBusInterface $bus,
        private readonly OrderMinimum $minimum,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    /**
     * Мінімум групи (E13 PR-7) за тими самими quotes, що підуть у рядки: сума рядків і сумарна кількість головних рядків.
     *
     * @param array<int, LineQuote> $quotes
     *
     * @throws OrderValidationException
     */
    private function assertMinimum(PlaceOrderRequest $request, array $quotes, ?CustomerGroup $group, string $currency): void
    {
        if (null === $group) {
            return;
        }
        $total = Money::zero($currency);
        $quantity = '0';
        foreach ($request->lines as $i => $line) {
            $lineQuantity = Quantity::normalize($line['quantity']);
            $total = $total->add($quotes[$i]->unitPrice()->multiplyByDecimal($lineQuantity));
            $quantity = bcadd($quantity, $lineQuantity, 3);
        }
        $shortfall = $this->minimum->shortfall($group, $total, $quantity);
        if (null !== $shortfall) {
            // ключ minimum, не lines: checkout показує локалізований блок _order_minimum і цей текст не дублює; швидке замовлення — флеш
            throw new OrderValidationException(['minimum' => $shortfall->message()]);
        }
    }

    /**
     * Збережений ключ ідемпотентності — sha256(ключ форми + нормалізований телефон): той самий ключ з іншим телефоном
     * (закешована CDN-ом форма, підбір) — інше замовлення, а не чужа сторінка «дякуємо» (консиліум PR #6, low).
     */
    public static function idempotencyKeyFor(?string $formKey, string $normalizedPhone): ?string
    {
        if (null === $formKey || '' === trim($formKey)) {
            return null;
        }

        return hash('sha256', trim($formKey).'|'.$normalizedPhone);
    }

    /** Назва рядка з обраними опціями — видно в листах, адмінці й CRM без знання снепшота. */
    private static function nameWithOptions(string $name, ?OptionConfiguration $configuration): string
    {
        $parts = [];
        foreach (\is_array($configuration?->snapshot['groups'] ?? null) ? $configuration->snapshot['groups'] : [] as $group) {
            $value = isset($group['labels']) && \is_array($group['labels']) ? implode(', ', $group['labels']) : trim(($group['value'] ?? '').' '.($group['unit'] ?? ''));
            $parts[] = $group['label'].': '.$value;
        }

        return [] === $parts ? $name : mb_substr($name.' ('.implode('; ', $parts).')', 0, 255);
    }

    /**
     * Ціни, за якими створюється замовлення, — ті, що бачив покупець: між сторінкою й submit могла закінчитись акція чи
     * змінитись ціна. Звіряються ті самі quotes, що підуть у рядки замовлення, тож перевірене = збережене.
     *
     * @param array<int, LineQuote> $quotes
     *
     * @throws PriceChangedException
     */
    private function assertPricesConfirmed(PlaceOrderRequest $request, array $quotes, ?CustomerGroup $group): void
    {
        $lines = [];
        $total = null;
        foreach ($request->lines as $i => $line) {
            $unit = $quotes[$i]->unitPrice();
            $quantity = Quantity::normalize($line['quantity']);
            $lines[] = ['sku' => $line['variant']->getSku(), 'quantity' => $quantity, 'unit' => $unit];
            $total = null === $total ? $unit->multiplyByDecimal($quantity) : $total->add($unit->multiplyByDecimal($quantity));
            if (\array_key_exists('card_price', $line)) {
                $card = $this->pricing->cardQuote($line['variant'], $request->locale, $group)->price;
                if ($card->amount !== $line['card_price']) {
                    throw new PriceChangedException($card, PriceFingerprint::of($lines));
                }
            }
        }
        if (null !== $request->expectedPrice && null !== $total) {
            $fingerprint = PriceFingerprint::of($lines);
            if (!hash_equals($fingerprint, $request->expectedPrice)) {
                throw new PriceChangedException($total, $fingerprint);
            }
        }
    }

    /** @throws OrderValidationException */
    public function place(PlaceOrderRequest $request): Order
    {
        $errors = [];
        $name = trim($request->customerName);
        if ('' === $name || mb_strlen($name) > 120) {
            $errors['customerName'] = 'Вкажіть ім\'я';
        }
        try {
            $phone = PhoneNormalizer::normalize($request->phone);
        } catch (InvalidArgumentException $e) {
            $phone = null;
            $errors['phone'] = $e->getMessage();
        }
        // ≤180 — довжина колонки sales_order.email (консиліум PR #6 п. 10)
        if (null !== $request->email && '' !== trim($request->email) && (mb_strlen(trim($request->email)) > 180 || false === filter_var(trim($request->email), \FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Не схоже на електронну пошту';
        }
        if ([] === $request->lines) {
            $errors['lines'] = 'Кошик порожній';
        }
        $customer = null === $request->customerId ? null : $this->em->find(Customer::class, $request->customerId);
        $group = null !== $customer && $customer->isActive() ? $customer->getCustomerGroup() : null;
        $currency = null;
        $quotes = [];
        foreach ($request->lines as $i => $line) {
            $variant = $line['variant'];
            if (!$variant->isOrderable()) {
                $errors['lines'] = \sprintf('Товар %s недоступний', $variant->getSku());
                continue;
            }
            try {
                $quantity = QuantityRules::assertAllowed($variant, $line['quantity']);
            } catch (CatalogInvariantViolation $e) {
                $errors['lines'] = $e->getMessage();
                continue;
            }
            if (StockPolicy::InStock === $variant->getStockPolicy() && Quantity::compare($variant->getStock(), $quantity) < 0) {
                $errors['lines'] = \sprintf('Недостатньо залишку для %s', $variant->getSku());
            }
            // ціна рядка (опції ADR Rev 9 + акції): вибір з кошика перераховується й фіксується тут; без вибору — значення
            // за замовчуванням, обов'язкова група без них — помилка (так само рахує CartCalculator)
            try {
                $quotes[$i] = $this->pricing->line($variant, $quantity, $line['options'] ?? null, $group, $request->locale);
            } catch (InvalidOptionsException $e) {
                $errors['lines'] = \sprintf('%s: %s', $variant->getSku(), $e->getMessage());
            }
            $currency ??= $variant->getCurrency();
            if ($currency !== $variant->getCurrency()) {
                $errors['lines'] = 'Товари в різних валютах';
            }
        }
        if ([] !== $errors || null === $phone || null === $currency) {
            throw new OrderValidationException($errors ?: ['lines' => 'Кошик порожній']);
        }
        $this->assertMinimum($request, $quotes, $group, $currency);

        $idempotencyKey = self::idempotencyKeyFor($request->idempotencyKey, $phone);
        if (null !== $idempotencyKey) {
            $existing = $this->em->getRepository(Order::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
            if (null !== $existing) {
                return $existing;
            }
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            if (null !== $customer) {
                $this->em->refresh($customer); // група покупця — станом на транзакцію, не на початок запиту
                $current = $customer->isActive() ? $customer->getCustomerGroup() : null;
                if ($current?->getId() !== $group?->getId()) {
                    $group = $current;
                    foreach ($request->lines as $i => $line) {
                        $quotes[$i] = $this->pricing->line($line['variant'], QuantityRules::assertAllowed($line['variant'], $line['quantity']), $line['options'] ?? null, $group, $request->locale);
                    }
                }
            }
            $this->assertMinimum($request, $quotes, $group, $currency); // група могла змінитись між сторінкою й submit
            $this->assertPricesConfirmed($request, $quotes, $group);
            $order = new Order($this->numbers->next(), $request->source, $request->locale, $name, $phone, $currency, $request->shippingMethod, $request->paymentMethod);
            $order->setEmail($request->email);
            $order->setComment($request->comment);
            $order->setShipping($request->shippingCity, $request->shippingAddress, $request->shippingPoint);
            $order->setIdempotencyKey($idempotencyKey);
            $order->setIp($request->ip);
            $order->setCustomer($customer, $group);

            foreach ($request->lines as $i => $line) {
                $variant = $line['variant'];
                $quantity = QuantityRules::assertAllowed($variant, $line['quantity']);
                $reserved = $this->stock->reserve($variant, $quantity);
                $translation = $variant->getProduct()->getTranslation($request->locale) ?? $variant->getProduct()->getTranslation($this->defaultLocale);
                /** @var LineQuote $quote */
                $quote = $quotes[$i];
                $configuration = $quote->configuration;
                $name = null !== $translation ? $translation->getName() : $variant->getSku();
                $item = new OrderItem($order, $variant, self::nameWithOptions($name, $configuration), $quote->unitPrice(), $quantity, $reserved);
                if (null !== $quote->listPrice()) {
                    $item->applyDiscount($quote->listPrice(), $quote->quote->promotionNames()); // снепшот акції (Ф3): до акції — з опціями й компонентами
                }
                $item->setOptionsSnapshot($configuration?->snapshot);
                $order->addItem($item);
                // компоненти (Rev 6): дочірні рядки зі своїм SKU і резервом, ціна 0 — вона вже в батьківському рядку;
                // суми й листи («у складі») дітей не рахують; у CRM діти йдуть з ціною 0 заради резерву (D7)
                foreach ($configuration->components ?? [] as $component) {
                    $componentQuantity = OptionConfiguration::componentQuantity($component['quantity'], $quantity);
                    $componentName = $component['variant']->getProduct()->getTranslation($request->locale)?->getName() ?? $component['variant']->getSku();
                    $child = new OrderItem($order, $component['variant'], $componentName, Money::zero($order->getCurrency()), $componentQuantity, $this->stock->reserve($component['variant'], $componentQuantity));
                    $child->setParent($item);
                    $order->addItem($child);
                }
            }
            $order->recalculateTotals();
            $order->addHistory(new OrderStatusHistory($order, null, $order->getStatus(), 'place', 'customer', $request->source->value));

            $this->em->persist($order);
            $this->em->flush();

            // outbox (D7): повідомлення в doctrine-транспорт у тій самій транзакції
            $this->bus->dispatch(new PushOrderToCrm((int) $order->getId()));
            $this->bus->dispatch(new SendCustomerOrderEmail((int) $order->getId()));
            $this->bus->dispatch(new SendShopOrderEmail((int) $order->getId()));

            $connection->commit();
        } catch (PriceChangedException $e) {
            $connection->rollBack();
            throw $e;
        } catch (OutOfStockException $e) {
            $connection->rollBack();
            throw new OrderValidationException(['lines' => $e->getMessage()], 0, $e);
        } catch (UniqueConstraintViolationException $e) {
            // гонка ідемпотентності: паралельний запит уже створив замовлення з цим ключем (консиліум PR #6 п. 4).
            // EntityManager після збою flush закритий — читаємо DBAL-ом; 1062 відкочує лише інструкцію, тож транзакція
            // жива, а блокуюче читання (FOR SHARE) бачить щойно закомічений рядок конкурента попри snapshot REPEATABLE READ
            $token = null === $idempotencyKey ? false : $connection->fetchOne('SELECT success_token FROM sales_order WHERE idempotency_key = ? FOR SHARE', [$idempotencyKey]);
            $connection->rollBack();
            if (\is_string($token) && '' !== $token) {
                throw new OrderAlreadyPlacedException($token, $e);
            }
            throw $e;
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $order;
    }
}

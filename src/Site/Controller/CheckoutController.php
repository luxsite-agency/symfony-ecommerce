<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Cart\CartCalculator;
use App\Core\Cart\CartResolver;
use App\Core\Customer\Repository\CustomerAddressRepository;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Exception\OrderAlreadyPlacedException;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Exception\PriceChangedException;
use App\Core\Order\Minimum\OrderMinimum;
use App\Core\Order\OrderSubmitGuard;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectory;
use App\Site\Customer\CustomerContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Повний checkout — одна server-rendered сторінка (ADR D8): контакт + доставка + оплата + коментар, PRG, без JS.
 * Нова пошта (Ф3, D9): з довідником — автодоповнення міста й select відділень (JS поверх, фолбек — select від сервера
 * або текст), снепшот відділення в замовленні; без ключа/довідника — текстові поля як раніше.
 * Ідемпотентність — прихований ключ у формі; rate limiter на submit; dataLayer: begin_checkout на GET,
 * add_shipping_info/add_payment_info — у чергу перед сторінкою «дякуємо».
 * Ціна: форма несе відбиток цін сторінки (price), PlaceOrder звіряє його в транзакції; змінилось — замовлення нема,
 * сторінка з поясненням, новою сумою й новим відбитком, покупець підтверджує ще раз.
 * Покупець з токена (CustomerContext): група в розрахунок кошика, customerId у PlaceOrderRequest (PlaceOrder сам
 * перечитує групу в транзакції); з форми ні покупець, ні група не беруться.
 */
final class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly CartCalculator $calculator,
        private readonly PlaceOrder $placeOrder,
        private readonly DataLayerCollector $dataLayer,
        private readonly EcommerceEventBuilder $events,
        private readonly OrderSubmitGuard $submitGuard,
        private readonly NovaPoshtaDirectory $novaPoshta,
        private readonly TranslatorInterface $translator,
        private readonly CustomerContext $customers,
        private readonly CustomerAddressRepository $addresses,
        private readonly OrderMinimum $minimum,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $cart = $this->carts->current();
        $totals = null === $cart ? null : $this->calculator->calculate($cart, $this->customers->group());
        if (null === $cart || null === $totals || 0 === $totals->count()) {
            return $this->redirectToRoute('site_cart');
        }
        $items = [];
        foreach ($totals->lines as $line) {
            $items[] = $this->events->item($line->item->getVariant(), $line->item->getQuantity(), null, null, $request->getLocale(), $line->unitPrice, $line->listPrice ?? $line->unitPrice);
        }
        $data = ['name' => '', 'phone' => '', 'email' => '', 'comment' => '', 'shipping' => ShippingMethod::NovaPoshta->value, 'city' => '', 'address' => '', 'np_city_ref' => '', 'np_warehouse_ref' => '', 'payment' => PaymentMethod::CashOnDelivery->value, 'key' => Uuid::v4()->toRfc4122(), 'price' => ''];
        // покупець з кабінету (E12 PR-6): контакти з профілю, адреса — за замовчуванням або ?address=<id> зі списку (лише свої)
        $customer = $this->customers->customer();
        $addresses = null === $customer ? [] : $this->addresses->findForCustomer($customer);
        $chosen = null;
        if (null !== $customer && !$request->isMethod('POST')) {
            $data['name'] = $customer->getName();
            $data['phone'] = $customer->getPhone() ?? '';
            $data['email'] = $customer->getEmail();
            $wanted = $request->query->getInt('address');
            foreach ($addresses as $address) {
                if ($address->getId() === $wanted || (0 === $wanted && $address->isDefault())) {
                    $chosen = $address;
                }
            }
            if (null !== $chosen) {
                $data['name'] = $chosen->getRecipientName();
                $data['phone'] = $chosen->getRecipientPhone();
                $data['shipping'] = $chosen->getShippingMethod()->value;
                $data['city'] = (string) $chosen->getCity();
                $point = $chosen->getShippingPoint();
                $data['address'] = null === $point ? (string) $chosen->getAddress() : '';
                $data['np_city_ref'] = $point->cityRef ?? '';
                $data['np_warehouse_ref'] = $point->warehouseRef ?? '';
            }
        }
        $errors = [];
        $npEnabled = $this->novaPoshta->isEnabled();
        $npWarehouses = []; // фолбек без JS: місто впізнано, відділення не обрано → select відділень цього міста

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Недійсний CSRF-токен');
            }
            $this->submitGuard->check($request, (string) $request->request->get('phone', ''));
            foreach (array_keys($data) as $field) {
                $data[$field] = trim((string) $request->request->get($field, $data[$field]));
            }
            $shippingPoint = null;
            if ($npEnabled && ShippingMethod::NovaPoshta->value === $data['shipping']) {
                $shippingPoint = $this->novaPoshta->pointFor($data['np_warehouse_ref'], $request->getLocale());
                if (null === $shippingPoint && '' === $data['address']) {
                    $city = '' !== $data['np_city_ref'] ? $this->novaPoshta->city($data['np_city_ref']) : $this->novaPoshta->findCityByName($data['city'], $request->getLocale());
                    if (null !== $city) {
                        $data['np_city_ref'] = $city->ref;
                        $data['city'] = $city->name($request->getLocale());
                        $npWarehouses = $this->novaPoshta->warehouses($city->ref, $request->getLocale());
                        $errors['address'] = $this->translator->trans('site.checkout.np.choose_warehouse');
                    }
                }
            }
            if ([] !== $errors) {
                // місто впізнано, відділення не обрано — форма з select відділень, нічого не оформлюємо
            } elseif ($totals->hasProblems()) {
                $errors['lines'] = 'У кошику є позиції, які не можна замовити — перевірте кошик';
            } else {
                try {
                    $order = $this->placeOrder->place(new PlaceOrderRequest(
                        source: OrderSource::Checkout,
                        locale: $request->getLocale(),
                        customerName: $data['name'],
                        phone: $data['phone'],
                        lines: array_map(static fn ($line): array => ['variant' => $line->item->getVariant(), 'quantity' => $line->item->getQuantity(), 'options' => $line->item->getOptionsSnapshot()], $totals->lines),
                        email: '' === $data['email'] ? null : $data['email'],
                        comment: '' === $data['comment'] ? null : $data['comment'],
                        shippingMethod: ShippingMethod::tryFrom($data['shipping']) ?? ShippingMethod::NovaPoshta,
                        shippingCity: '' === $data['city'] ? null : $data['city'],
                        shippingAddress: '' === $data['address'] ? null : $data['address'],
                        paymentMethod: PaymentMethod::tryFrom($data['payment']) ?? PaymentMethod::CashOnDelivery,
                        idempotencyKey: $data['key'],
                        ip: $request->getClientIp(),
                        shippingPoint: $shippingPoint,
                        expectedPrice: $data['price'],
                        customerId: $this->customers->customerId(),
                    ));
                    $this->dataLayer->queue($this->events->addShippingInfo($items, $data['shipping']));
                    $this->dataLayer->queue($this->events->addPaymentInfo($items, $data['payment']));
                    $this->carts->forget();

                    return $this->redirectToRoute('site_order_thanks', ['token' => $order->getSuccessToken()]);
                } catch (OrderAlreadyPlacedException $e) {
                    // подвійний сабміт наздогнав паралельний запит — на «дякуємо» вже створеного замовлення
                    $this->carts->forget();

                    return $this->redirectToRoute('site_order_thanks', ['token' => $e->successToken]);
                } catch (PriceChangedException $e) {
                    // сторінка з новими цінами рядків і новим відбитком — покупець підтверджує ще раз
                    $totals = $this->calculator->calculate($cart, $this->customers->group());
                    $errors['price'] = $this->translator->trans('site.checkout.price_changed', ['total' => $e->total->format($request->getLocale())]);
                } catch (OrderValidationException $e) {
                    $errors = $e->errors;
                    if (isset($errors['minimum'])) {
                        // мінімум групи: сторінка показує локалізований блок _order_minimum з тими самими числами (E13 PR-7);
                        // текст PlaceOrder лишається лише якщо блок не покаже (група змінилась між сторінкою й submit)
                        $shortfall = $this->minimum->shortfall($this->customers->group(), $totals->itemsTotal, $totals->totalQuantity());
                        if (null === $shortfall) {
                            $errors['lines'] = $errors['minimum'];
                        }
                        unset($errors['minimum']);
                        $errors['minimum_blocked'] = '1';
                    }
                }
            }
        } else {
            if (null !== $chosen?->getShippingPoint() && $npEnabled) {
                // обране відділення з кабінету видно й без JS: select відділень цього міста з уже вибраним
                $npWarehouses = $this->novaPoshta->warehouses($chosen->getShippingPoint()->cityRef, $request->getLocale());
            }
            $this->dataLayer->push($this->events->beginCheckout($items));
        }
        // форма повертається з іншою помилкою (телефон, вибір відділення, нестача), а ціна тим часом змінилась:
        // сторінка вже покаже нову суму і новий відбиток — без пояснення наступний сабміт пройшов би мовчки
        if ($request->isMethod('POST') && [] !== $errors && !isset($errors['price']) && !hash_equals($totals->priceFingerprint(), $data['price'])) {
            $errors['price'] = $this->translator->trans('site.checkout.price_changed', ['total' => $totals->itemsTotal->format($request->getLocale())]);
        }

        return $this->render('site/checkout/index.html.twig', [
            'totals' => $totals,
            'shortfall' => $this->minimum->shortfall($this->customers->group(), $totals->itemsTotal, $totals->totalQuantity()),
            'data' => $data,
            'errors' => $errors,
            'shippingMethods' => ShippingMethod::selectable(),
            'paymentMethods' => PaymentMethod::selectable(),
            'np' => $npEnabled ? ['warehouses' => $npWarehouses] : null,
            'addresses' => $addresses,
            'chosenAddress' => $chosen?->getId(),
        ], new Response(null, [] === $errors ? 200 : 422));
    }
}

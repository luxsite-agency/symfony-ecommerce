<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;

/**
 * Детермінований план демо-замовлень (без rand): номери 1–3 — як у перших демо-даних, 4–14 — гості, 21–28 — роздрібний
 * демо-покупець, 31–35 — оптовий (ціни групи). Дні «тому» розкидані по останніх ~180 днях, статуси — різні, суми —
 * різні, щоб дашборд і вибір періоду мали що показати. Товари — з великим залишком у DemoCatalog (резерви штатні).
 *
 * @phpstan-import-type OrderPlan from DemoOrderFactory
 */
final class DemoOrderPlans
{
    /** @return array<int, OrderPlan> */
    public static function guests(): array
    {
        $np = [ShippingMethod::NovaPoshta, PaymentMethod::CashOnDelivery, 'Київ', 'Відділення № 1 (демо)'];
        $courier = [ShippingMethod::Courier, PaymentMethod::BankTransfer, 'Київ', 'вул. Демонстраційна, 1'];
        $manager = [ShippingMethod::ManagerContact, PaymentMethod::ManagerContact, null, null];
        $pickup = [ShippingMethod::Pickup, PaymentMethod::CashOnDelivery, 'Львів', 'Самовивіз зі складу (демо)'];

        return [
            1 => self::plan('Олена Демо', OrderSource::Checkout, $np, [['DV-P01', 0, '1'], ['FT-001', 0, '1']], [], 1),
            2 => self::plan('Андрій Демо', OrderSource::Quick, $manager, [['TL-D02', 0, '1']], ['confirm'], 3),
            3 => self::plan('ТОВ «Демо Склад»', OrderSource::Checkout, $courier, [['RK-S01', 0, '2'], ['MT-001', 0, '2.5']], ['confirm', 'process', 'ship'], 6),
            4 => self::plan('Марія Демо', OrderSource::Checkout, $np, [['TL-H05', 0, '2'], ['TL-H02', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 10),
            5 => self::plan('Ігор Демо', OrderSource::Quick, $manager, [['TL-G01', 0, '1']], ['cancel'], 15),
            6 => self::plan('Наталія Демо', OrderSource::Checkout, $courier, [['FT-003', 0, '12'], ['FT-006', 0, '5']], ['confirm', 'process', 'ship', 'complete'], 22),
            7 => self::plan('Сергій Демо', OrderSource::Checkout, $pickup, [['TL-H03', 0, '3']], ['confirm', 'process', 'ship', 'complete'], 31),
            8 => self::plan('ФОП Демченко', OrderSource::Checkout, $courier, [['MT-001', 0, '36'], ['FT-004', 0, '2']], ['confirm', 'process', 'ship', 'complete'], 44),
            9 => self::plan('Оксана Демо', OrderSource::Quick, $manager, [['TL-H04', 0, '2']], ['confirm', 'cancel'], 58),
            10 => self::plan('Дмитро Демо', OrderSource::Checkout, $np, [['FT-001', 0, '3'], ['FT-005', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 75),
            11 => self::plan('Тетяна Демо', OrderSource::Checkout, $np, [['DE-001', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 96),
            12 => self::plan('Василь Демо', OrderSource::Checkout, $courier, [['RK-S01', 0, '1'], ['FT-003', 0, '8']], ['confirm', 'process', 'ship', 'complete'], 121),
            13 => self::plan('Ірина Демо', OrderSource::Quick, $manager, [['TL-H05', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 149),
            14 => self::plan('Петро Демо', OrderSource::Checkout, $np, [['FT-006', 0, '10'], ['TL-H02', 0, '2']], ['confirm', 'process', 'ship', 'complete'], 176),
        ];
    }

    /**
     * Роздрібний демо-покупець: 8 замовлень у всіх станах.
     *
     * @return array<int, OrderPlan>
     */
    public static function retail(string $name): array
    {
        $np = [ShippingMethod::NovaPoshta, PaymentMethod::CashOnDelivery, 'Київ', 'Відділення № 12 (демо)'];
        $courier = [ShippingMethod::Courier, PaymentMethod::BankTransfer, 'Київ', 'просп. Демонстраційний, 7, кв. 3'];

        return [
            21 => self::plan($name, OrderSource::Checkout, $np, [['FT-001', 0, '2'], ['TL-H02', 0, '1']], [], 2),
            22 => self::plan($name, OrderSource::Checkout, $np, [['TL-H03', 0, '2']], ['confirm'], 9),
            23 => self::plan($name, OrderSource::Checkout, $courier, [['DV-P02', 0, '1'], ['FT-004', 0, '1']], ['confirm', 'process'], 19),
            24 => self::plan($name, OrderSource::Checkout, $np, [['TL-G01', 0, '1']], ['confirm', 'process', 'ship'], 33),
            25 => self::plan($name, OrderSource::Checkout, $np, [['FT-003', 0, '6'], ['FT-006', 0, '3']], ['confirm', 'process', 'ship', 'complete'], 52),
            26 => self::plan($name, OrderSource::Quick, [ShippingMethod::ManagerContact, PaymentMethod::ManagerContact, null, null], [['TL-H04', 0, '1']], ['cancel'], 77),
            27 => self::plan($name, OrderSource::Checkout, $courier, [['MT-001', 0, '12'], ['FT-005', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 118),
            28 => self::plan($name, OrderSource::Checkout, $np, [['TL-H05', 0, '3']], ['confirm', 'process', 'ship', 'complete'], 164),
        ];
    }

    /**
     * Оптовий демо-покупець: 5 замовлень з порогами групи (RK-S01, MT-001, TL-D04 мають групові пороги).
     *
     * @return array<int, OrderPlan>
     */
    public static function wholesale(string $companyName): array
    {
        $courier = [ShippingMethod::Courier, PaymentMethod::BankTransfer, 'Київ', 'вул. Складська, 3 (демо)'];

        return [
            31 => self::plan($companyName, OrderSource::Checkout, $courier, [['RK-S01', 0, '1'], ['MT-001', 0, '20']], ['confirm'], 4),
            32 => self::plan($companyName, OrderSource::Checkout, $courier, [['FT-003', 0, '20'], ['FT-006', 0, '10']], ['confirm', 'process'], 18),
            33 => self::plan($companyName, OrderSource::Checkout, $courier, [['MT-001', 0, '50'], ['TL-D04', 0, '1']], ['confirm', 'process', 'ship', 'complete'], 47),
            34 => self::plan($companyName, OrderSource::Checkout, $courier, [['FT-003', 0, '30']], ['confirm', 'process', 'ship', 'complete'], 92),
            35 => self::plan($companyName, OrderSource::Checkout, $courier, [['RK-S01', 0, '1'], ['FT-006', 0, '20'], ['MT-001', 0, '10']], ['confirm', 'process', 'ship', 'complete'], 153),
        ];
    }

    /**
     * @param array{ShippingMethod, PaymentMethod, ?string, ?string} $delivery
     * @param list<array{string, int, string}>                       $lines
     * @param list<string>                                           $transitions
     *
     * @return OrderPlan
     */
    private static function plan(string $name, OrderSource $source, array $delivery, array $lines, array $transitions, int $daysAgo): array
    {
        [$shipping, $payment, $city, $address] = $delivery;

        return ['name' => $name, 'source' => $source, 'shipping' => $shipping, 'payment' => $payment, 'city' => $city, 'address' => $address, 'lines' => $lines, 'transitions' => $transitions, 'daysAgo' => $daysAgo];
    }
}

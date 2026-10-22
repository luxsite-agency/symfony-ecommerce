<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

/**
 * Структура демо-каталогу: характеристики, дерево категорій, бренди, товари з варіантами, цінами й наявністю.
 * Кожна функція рушія має тут що показати: осі варіантів, фасети всіх типів, порогові й групові ціни, «ціна за
 * запитом», дробова кількість, під замовлення, опції-компоненти, набір. Тексти — DemoTexts.
 *
 * Позначки демо-даних (за ними працює --purge): SKU `DEMO-…`, коди характеристик і груп `demo_…`,
 * слаги категорій, брендів, сторінок і записів `demo-…`, файли медіа `demo-…`.
 *
 * @phpstan-type Tr array<string, string>
 * @phpstan-type AttributeSpec array{type: string, unit?: string, filterable: bool, axis: bool, name: Tr, values?: array<string, Tr>}
 * @phpstan-type CategorySpec array{parent: ?string, filters: list<string>}
 * @phpstan-type VariantSpec array{axes: array<string, string>, price?: int, old?: int, stock?: string}
 * @phpstan-type TierSpec array{min: string, price: int, group?: bool}
 * @phpstan-type PromotionSpec array{type: 'percent'|'amount', value: string, categories?: list<string>, brands?: list<string>, whole?: bool, active?: bool}
 * @phpstan-type ProductSpec array{categories: list<string>, brand?: string, price: int, old?: int, mode?: string, kind?: string, stock: string, unit?: string, min?: string, step?: string, attributes?: array<string, string|bool>, variants?: list<VariantSpec>, tiers?: list<TierSpec>, options?: string, images: int}
 */
final class DemoCatalog
{
    public const string SKU_PREFIX = 'DEMO-';
    public const string CODE_PREFIX = 'demo_';
    public const string SLUG_PREFIX = 'demo-';
    public const string CUSTOMER_GROUP = 'demo_wholesale';
    /** Мінімальна сума замовлення оптової демо-групи, грн (E13 PR-7). */
    public const int WHOLESALE_MIN_ORDER = 3000;
    /** Позначка демо-замовлення: початок коментаря і телефон-заглушка +380000000000..9. */
    public const string ORDER_MARK = 'DEMO';
    /** Телефон демо-замовлення: префікс + номер двома цифрами (+380000000001…99) — у purge LIKE 'префікс__'. */
    public const string ORDER_PHONE_PREFIX = '+3800000000';
    /** Початок назви демо-акції: за ним сід шукає акцію, а --purge видаляє. */
    public const string PROMOTION_PREFIX = 'DEMO: ';

    /** @return array<string, AttributeSpec> ключ — код без префікса demo_ */
    public static function attributes(): array
    {
        return [
            'door_size' => [
                'type' => 'select', 'filterable' => true, 'axis' => true,
                'name' => ['uk' => 'Розмір полотна', 'ru' => 'Размер полотна', 'en' => 'Door leaf size'],
                'values' => [
                    'w600' => ['uk' => '600×2000 мм', 'ru' => '600×2000 мм', 'en' => '600×2000 mm'],
                    'w700' => ['uk' => '700×2000 мм', 'ru' => '700×2000 мм', 'en' => '700×2000 mm'],
                    'w800' => ['uk' => '800×2000 мм', 'ru' => '800×2000 мм', 'en' => '800×2000 mm'],
                    'w900' => ['uk' => '900×2000 мм', 'ru' => '900×2000 мм', 'en' => '900×2000 mm'],
                ],
            ],
            'color' => [
                'type' => 'select', 'filterable' => true, 'axis' => true,
                'name' => ['uk' => 'Колір', 'ru' => 'Цвет', 'en' => 'Colour'],
                'values' => [
                    'white' => ['uk' => 'Білий', 'ru' => 'Белый', 'en' => 'White'],
                    'grey' => ['uk' => 'Сірий', 'ru' => 'Серый', 'en' => 'Grey'],
                    'black' => ['uk' => 'Чорний', 'ru' => 'Черный', 'en' => 'Black'],
                    'anthracite' => ['uk' => 'Антрацит', 'ru' => 'Антрацит', 'en' => 'Anthracite'],
                    'oak' => ['uk' => 'Дуб', 'ru' => 'Дуб', 'en' => 'Oak'],
                    'walnut' => ['uk' => 'Горіх', 'ru' => 'Орех', 'en' => 'Walnut'],
                ],
            ],
            'material' => [
                'type' => 'select', 'filterable' => true, 'axis' => false,
                'name' => ['uk' => 'Матеріал', 'ru' => 'Материал', 'en' => 'Material'],
                'values' => [
                    'mdf' => ['uk' => 'МДФ', 'ru' => 'МДФ', 'en' => 'MDF'],
                    'veneer_oak' => ['uk' => 'Шпон дуба', 'ru' => 'Шпон дуба', 'en' => 'Oak veneer'],
                    'veneer_walnut' => ['uk' => 'Шпон горіха', 'ru' => 'Шпон ореха', 'en' => 'Walnut veneer'],
                    'steel' => ['uk' => 'Сталь', 'ru' => 'Сталь', 'en' => 'Steel'],
                    'galvanized' => ['uk' => 'Оцинкована сталь', 'ru' => 'Оцинкованная сталь', 'en' => 'Galvanised steel'],
                    'aluminium' => ['uk' => 'Алюміній', 'ru' => 'Алюминий', 'en' => 'Aluminium'],
                    'brass' => ['uk' => 'Латунь', 'ru' => 'Латунь', 'en' => 'Brass'],
                    'cr_v' => ['uk' => 'Хром-ванадієва сталь', 'ru' => 'Хром-ванадиевая сталь', 'en' => 'Chrome vanadium steel'],
                ],
            ],
            'glass' => ['type' => 'boolean', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Зі склом', 'ru' => 'Со стеклом', 'en' => 'With glass']],
            'closer' => ['type' => 'boolean', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Доводчик у комплекті', 'ru' => 'Доводчик в комплекте', 'en' => 'Door closer included']],
            'thickness' => ['type' => 'number', 'unit' => 'мм', 'filterable' => false, 'axis' => false, 'name' => ['uk' => 'Товщина полотна', 'ru' => 'Толщина полотна', 'en' => 'Door leaf thickness']],
            'width' => ['type' => 'number', 'unit' => 'мм', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Ширина', 'ru' => 'Ширина', 'en' => 'Width']],
            'weight' => ['type' => 'number', 'unit' => 'кг', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Вага', 'ru' => 'Вес', 'en' => 'Weight']],
            'power' => ['type' => 'number', 'unit' => 'Вт', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Потужність', 'ru' => 'Мощность', 'en' => 'Power']],
            'disc' => ['type' => 'number', 'unit' => 'мм', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Діаметр диска', 'ru' => 'Диаметр диска', 'en' => 'Disc diameter']],
            'cordless' => ['type' => 'boolean', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Акумуляторний', 'ru' => 'Аккумуляторный', 'en' => 'Cordless']],
            'shelves' => ['type' => 'number', 'unit' => 'шт', 'filterable' => true, 'axis' => false, 'name' => ['uk' => 'Кількість полиць', 'ru' => 'Количество полок', 'en' => 'Number of shelves']],
            'rack_height' => [
                'type' => 'select', 'filterable' => true, 'axis' => true,
                'name' => ['uk' => 'Висота', 'ru' => 'Высота', 'en' => 'Height'],
                'values' => [
                    'h1800' => ['uk' => '1800 мм', 'ru' => '1800 мм', 'en' => '1800 mm'],
                    'h2000' => ['uk' => '2000 мм', 'ru' => '2000 мм', 'en' => '2000 mm'],
                    'h2500' => ['uk' => '2500 мм', 'ru' => '2500 мм', 'en' => '2500 mm'],
                    'h3000' => ['uk' => '3000 мм', 'ru' => '3000 мм', 'en' => '3000 mm'],
                    'h4000' => ['uk' => '4000 мм', 'ru' => '4000 мм', 'en' => '4000 mm'],
                ],
            ],
            'shelf_load' => [
                'type' => 'select', 'filterable' => true, 'axis' => true,
                'name' => ['uk' => 'Навантаження на полицю', 'ru' => 'Нагрузка на полку', 'en' => 'Shelf load'],
                'values' => [
                    'l100' => ['uk' => 'до 100 кг', 'ru' => 'до 100 кг', 'en' => 'up to 100 kg'],
                    'l200' => ['uk' => 'до 200 кг', 'ru' => 'до 200 кг', 'en' => 'up to 200 kg'],
                    'l300' => ['uk' => 'до 300 кг', 'ru' => 'до 300 кг', 'en' => 'up to 300 kg'],
                ],
            ],
        ];
    }

    /**
     * Дерево в порядку створення (батько раніше за дитину). filters — власний список фільтрів категорії
     * (CategoryFilterAttribute); без списку діє список найближчого предка.
     *
     * @return array<string, CategorySpec>
     */
    public static function categories(): array
    {
        return [
            'dveri' => ['parent' => null, 'filters' => ['color', 'material']],
            'interior' => ['parent' => 'dveri', 'filters' => ['door_size', 'color', 'material', 'glass']],
            'painted' => ['parent' => 'interior', 'filters' => []],
            'veneer' => ['parent' => 'interior', 'filters' => []],
            'entrance' => ['parent' => 'dveri', 'filters' => ['color', 'width', 'weight', 'closer']],
            'fittings' => ['parent' => 'dveri', 'filters' => ['material']],
            'racks' => ['parent' => null, 'filters' => ['rack_height', 'width']],
            'warehouse' => ['parent' => 'racks', 'filters' => ['rack_height', 'shelf_load', 'shelves', 'width']],
            'shelf_racks' => ['parent' => 'warehouse', 'filters' => []],
            'pallet_racks' => ['parent' => 'warehouse', 'filters' => ['rack_height', 'width']],
            'metal' => ['parent' => 'racks', 'filters' => ['weight']],
            'tools' => ['parent' => null, 'filters' => ['power', 'weight', 'cordless']],
            'power_tools' => ['parent' => 'tools', 'filters' => ['power', 'weight', 'cordless']],
            'drills' => ['parent' => 'power_tools', 'filters' => []],
            'grinders' => ['parent' => 'power_tools', 'filters' => ['power', 'disc', 'cordless', 'weight']],
            'hand_tools' => ['parent' => 'tools', 'filters' => ['material', 'weight']],
        ];
    }

    /** @return array<string, string> частина слага (після demo-) → назва бренду */
    public static function brands(): array
    {
        return [
            'dvermaster' => 'Dvermaster',
            'portalia' => 'Portalia',
            'bastion' => 'Bastion',
            'stalform' => 'Stalform',
            'regalis' => 'Regalis',
            'toolmark' => 'Toolmark',
            'workline' => 'Workline',
        ];
    }

    /** @return array<string, string> ключ кореневої категорії → колір фону зображень */
    public static function palette(): array
    {
        return ['dveri' => '#8a6f58', 'racks' => '#4f6475', 'tools' => '#b0662f'];
    }

    /**
     * Демо-акції: % на категорію з підкатегоріями, сума за одиницю на бренд і вимкнена акція на весь каталог.
     * Сума — у валюті магазину (app.default_currency).
     *
     * @return array<string, PromotionSpec> назва без PROMOTION_PREFIX → умови
     */
    public static function promotions(): array
    {
        return [
            'інструмент −10 %' => ['type' => 'percent', 'value' => '10', 'categories' => ['tools']],
            'двері Portalia −300 грн' => ['type' => 'amount', 'value' => '300', 'brands' => ['portalia']],
            'весь каталог −5 % (вимкнена)' => ['type' => 'percent', 'value' => '5', 'whole' => true, 'active' => false],
        ];
    }

    /** @return array<string, string> код кольору осі → колір фону зображення варіанта */
    public static function swatches(): array
    {
        return ['white' => '#d9d6cf', 'grey' => '#8e9296', 'black' => '#2f3133', 'anthracite' => '#43484d', 'oak' => '#b38b5d', 'walnut' => '#6b4a33'];
    }

    /**
     * stock: `in:<кількість>` — облікований залишок, `out` — облікований нуль, `order:<днів>` — під замовлення,
     * `free` — без обліку. price/old — гривні (у мінорні одиниці переводить сідер). mode: fixed|from|on_request.
     * Порядок важливий: фурнітура раніше за двері, бо двері беруть її варіанти компонентами опцій.
     *
     * @return array<string, ProductSpec> ключ = SKU без префікса DEMO-
     */
    public static function products(): array
    {
        $doorHw = 'door_hardware';

        return [
            // --- фурнітура й погонаж (компоненти опцій дверей і набору) ---
            'FT-001' => ['categories' => ['fittings'], 'brand' => 'dvermaster', 'price' => 650, 'stock' => 'in:40', 'attributes' => ['material' => 'aluminium'], 'images' => 1],
            'FT-002' => ['categories' => ['fittings'], 'brand' => 'dvermaster', 'price' => 890, 'old' => 990, 'stock' => 'in:15', 'attributes' => ['material' => 'brass'], 'images' => 1],
            'FT-003' => ['categories' => ['fittings'], 'price' => 180, 'stock' => 'in:200', 'attributes' => ['material' => 'steel'], 'tiers' => [['min' => '10', 'price' => 160]], 'images' => 1],
            'FT-004' => ['categories' => ['fittings'], 'price' => 520, 'stock' => 'in:30', 'attributes' => ['material' => 'steel'], 'images' => 1],
            'FT-005' => ['categories' => ['fittings'], 'brand' => 'dvermaster', 'price' => 1350, 'stock' => 'in:20', 'attributes' => ['material' => 'veneer_oak'], 'images' => 1],
            'FT-006' => ['categories' => ['fittings'], 'brand' => 'dvermaster', 'price' => 210, 'stock' => 'in:120', 'attributes' => ['material' => 'veneer_oak'], 'images' => 1],
            'FT-007' => ['categories' => ['fittings', 'entrance'], 'brand' => 'bastion', 'price' => 1450, 'stock' => 'in:12', 'attributes' => ['material' => 'steel', 'weight' => '1.8'], 'images' => 2],

            // --- міжкімнатні фарбовані: осі розмір × колір ---
            'DV-P01' => [
                'categories' => ['painted'], 'brand' => 'dvermaster', 'price' => 4200, 'mode' => 'from', 'stock' => 'in:6', 'options' => $doorHw,
                'attributes' => ['material' => 'mdf', 'glass' => false, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w700', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'white'], 'price' => 4400],
                    ['axes' => ['door_size' => 'w700', 'color' => 'grey'], 'stock' => 'out'],
                    ['axes' => ['door_size' => 'w800', 'color' => 'grey'], 'price' => 4400, 'stock' => 'in:3'],
                ],
                'images' => 3,
            ],
            'DV-P02' => [
                'categories' => ['painted'], 'brand' => 'dvermaster', 'price' => 4800, 'stock' => 'in:4', 'options' => $doorHw,
                'attributes' => ['material' => 'mdf', 'glass' => true, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w700', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w900', 'color' => 'white'], 'price' => 5200, 'stock' => 'order:10'],
                ],
                'images' => 2,
            ],
            'DV-P03' => [
                'categories' => ['painted'], 'brand' => 'portalia', 'price' => 5100, 'old' => 5600, 'stock' => 'in:5', 'options' => $doorHw,
                'attributes' => ['material' => 'mdf', 'glass' => false, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w800', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'black']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'grey'], 'stock' => 'in:2'],
                ],
                'images' => 3,
            ],
            'DV-P04' => [
                'categories' => ['painted'], 'brand' => 'portalia', 'price' => 3600, 'mode' => 'from', 'stock' => 'in:8', 'options' => $doorHw,
                'attributes' => ['material' => 'mdf', 'glass' => false, 'thickness' => '38'],
                'variants' => [
                    ['axes' => ['door_size' => 'w600', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w700', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'white']],
                    ['axes' => ['door_size' => 'w900', 'color' => 'white'], 'price' => 3900],
                ],
                'images' => 2,
            ],
            'DV-P05' => [
                'categories' => ['painted'], 'brand' => 'dvermaster', 'price' => 6300, 'stock' => 'in:3', 'options' => $doorHw,
                'attributes' => ['material' => 'mdf', 'glass' => true, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w700', 'color' => 'black']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'black']],
                    ['axes' => ['door_size' => 'w700', 'color' => 'anthracite']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'anthracite'], 'stock' => 'order:7'],
                ],
                'images' => 3,
            ],
            'DV-P06' => [
                'categories' => ['painted'], 'brand' => 'portalia', 'price' => 2900, 'stock' => 'out',
                'attributes' => ['material' => 'mdf', 'glass' => false, 'thickness' => '35', 'color' => 'white'],
                'images' => 1,
            ],

            // --- міжкімнатні шпоновані: під замовлення, ціна за запитом, набір ---
            'DV-V01' => [
                'categories' => ['veneer'], 'brand' => 'dvermaster', 'price' => 7800, 'stock' => 'order:14', 'options' => $doorHw,
                'attributes' => ['material' => 'veneer_oak', 'glass' => false, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w700', 'color' => 'oak']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'oak']],
                    ['axes' => ['door_size' => 'w700', 'color' => 'walnut'], 'price' => 8200],
                    ['axes' => ['door_size' => 'w800', 'color' => 'walnut'], 'price' => 8200],
                ],
                'images' => 3,
            ],
            'DV-V02' => [
                'categories' => ['veneer'], 'brand' => 'portalia', 'price' => 9400, 'stock' => 'order:21', 'options' => $doorHw,
                'attributes' => ['material' => 'veneer_oak', 'glass' => true, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['door_size' => 'w700', 'color' => 'oak']],
                    ['axes' => ['door_size' => 'w800', 'color' => 'oak']],
                    ['axes' => ['door_size' => 'w900', 'color' => 'oak'], 'price' => 9900],
                ],
                'images' => 2,
            ],
            'DV-V03' => [
                'categories' => ['veneer'], 'brand' => 'portalia', 'price' => 0, 'mode' => 'on_request', 'stock' => 'order:30',
                'attributes' => ['material' => 'veneer_walnut', 'glass' => false, 'thickness' => '42', 'color' => 'walnut'],
                'images' => 1,
            ],
            'DV-V04' => [
                'categories' => ['veneer'], 'brand' => 'dvermaster', 'price' => 6900, 'stock' => 'in:4', 'options' => $doorHw,
                'attributes' => ['material' => 'veneer_oak', 'glass' => false, 'thickness' => '40'],
                'variants' => [
                    ['axes' => ['color' => 'oak']],
                    ['axes' => ['color' => 'walnut'], 'stock' => 'in:1'],
                ],
                'images' => 2,
            ],
            'DV-K01' => [
                'categories' => ['veneer'], 'brand' => 'dvermaster', 'kind' => 'bundle', 'price' => 7800, 'stock' => 'order:14', 'options' => 'door_kit',
                'attributes' => ['material' => 'veneer_oak', 'glass' => false, 'thickness' => '40', 'color' => 'oak'],
                'images' => 2,
            ],

            // --- вхідні: одна ширина на картку, варіанти за кольором ---
            'DE-001' => [
                'categories' => ['entrance'], 'brand' => 'bastion', 'price' => 14500, 'stock' => 'in:3',
                'attributes' => ['material' => 'steel', 'width' => '860', 'weight' => '62', 'closer' => false],
                'variants' => [['axes' => ['color' => 'anthracite']], ['axes' => ['color' => 'black'], 'stock' => 'in:2']],
                'images' => 2,
            ],
            'DE-002' => [
                'categories' => ['entrance'], 'brand' => 'bastion', 'price' => 21900, 'stock' => 'in:2',
                'attributes' => ['material' => 'steel', 'width' => '960', 'weight' => '78', 'closer' => true],
                'variants' => [['axes' => ['color' => 'anthracite']], ['axes' => ['color' => 'oak'], 'price' => 22900, 'stock' => 'order:12']],
                'images' => 2,
            ],
            'DE-003' => [
                'categories' => ['entrance'], 'brand' => 'portalia', 'price' => 11800, 'old' => 12900, 'stock' => 'in:5',
                'attributes' => ['material' => 'steel', 'width' => '860', 'weight' => '55', 'closer' => false, 'color' => 'black'],
                'images' => 2,
            ],
            'DE-004' => [
                'categories' => ['entrance'], 'brand' => 'portalia', 'price' => 17400, 'stock' => 'order:10',
                'attributes' => ['material' => 'steel', 'width' => '960', 'weight' => '70', 'closer' => true, 'color' => 'grey'],
                'images' => 1,
            ],
            'DE-005' => [
                'categories' => ['entrance'], 'brand' => 'bastion', 'price' => 8900, 'stock' => 'in:10',
                'attributes' => ['material' => 'steel', 'width' => '860', 'weight' => '48', 'closer' => true, 'color' => 'grey'],
                'tiers' => [['min' => '3', 'price' => 8400], ['min' => '1', 'price' => 7900, 'group' => true]],
                'images' => 1,
            ],

            // --- полицеві стелажі: осі висота × навантаження, порогові й оптові ціни ---
            'RK-S01' => [
                'categories' => ['shelf_racks'], 'brand' => 'stalform', 'price' => 3200, 'mode' => 'from', 'stock' => 'in:15',
                'attributes' => ['material' => 'steel', 'width' => '1000', 'shelves' => '4'],
                'variants' => [
                    ['axes' => ['rack_height' => 'h1800', 'shelf_load' => 'l100']],
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l100'], 'price' => 3450],
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l200'], 'price' => 3900],
                    ['axes' => ['rack_height' => 'h2500', 'shelf_load' => 'l200'], 'price' => 4500, 'stock' => 'out'],
                ],
                'tiers' => [['min' => '5', 'price' => 3050], ['min' => '20', 'price' => 2880], ['min' => '1', 'price' => 2750, 'group' => true]],
                'images' => 2,
            ],
            'RK-S02' => [
                'categories' => ['shelf_racks'], 'brand' => 'stalform', 'price' => 5200, 'mode' => 'from', 'stock' => 'in:8',
                'attributes' => ['material' => 'steel', 'width' => '1200', 'shelves' => '5'],
                'variants' => [
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l200']],
                    ['axes' => ['rack_height' => 'h2500', 'shelf_load' => 'l200'], 'price' => 5800],
                    ['axes' => ['rack_height' => 'h2500', 'shelf_load' => 'l300'], 'price' => 6700, 'stock' => 'order:7'],
                ],
                'tiers' => [['min' => '5', 'price' => 4950]],
                'images' => 2,
            ],
            'RK-S03' => [
                'categories' => ['shelf_racks'], 'brand' => 'regalis', 'price' => 2750, 'stock' => 'in:12',
                'attributes' => ['material' => 'steel', 'width' => '900', 'shelves' => '6'],
                'variants' => [
                    ['axes' => ['rack_height' => 'h1800', 'shelf_load' => 'l100']],
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l100'], 'price' => 2950],
                ],
                'images' => 1,
            ],
            'RK-S04' => [
                'categories' => ['shelf_racks'], 'brand' => 'regalis', 'price' => 6400, 'stock' => 'order:7',
                'attributes' => ['material' => 'galvanized', 'width' => '1500', 'shelves' => '4'],
                'variants' => [
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l200']],
                    ['axes' => ['rack_height' => 'h2000', 'shelf_load' => 'l300'], 'price' => 7100],
                    ['axes' => ['rack_height' => 'h2500', 'shelf_load' => 'l300'], 'price' => 7900],
                ],
                'images' => 2,
            ],
            'RK-S05' => [
                'categories' => ['shelf_racks'], 'brand' => 'stalform', 'price' => 1900, 'stock' => 'in:25',
                'attributes' => ['material' => 'steel', 'width' => '800', 'shelves' => '5'],
                'tiers' => [['min' => '3', 'price' => 1780], ['min' => '1', 'price' => 1650, 'group' => true]],
                'images' => 1,
            ],

            // --- палетні: під замовлення, ціна за запитом ---
            'RK-P01' => [
                'categories' => ['pallet_racks'], 'brand' => 'regalis', 'price' => 12600, 'stock' => 'order:10',
                'attributes' => ['material' => 'steel', 'width' => '2700'],
                'variants' => [['axes' => ['rack_height' => 'h3000']], ['axes' => ['rack_height' => 'h4000'], 'price' => 15400]],
                'images' => 2,
            ],
            'RK-P02' => [
                'categories' => ['pallet_racks'], 'brand' => 'regalis', 'price' => 8300, 'stock' => 'order:10',
                'attributes' => ['material' => 'steel', 'width' => '2700'],
                'variants' => [['axes' => ['rack_height' => 'h3000']], ['axes' => ['rack_height' => 'h4000'], 'price' => 9900]],
                'tiers' => [['min' => '4', 'price' => 7900]],
                'images' => 1,
            ],
            'RK-P03' => [
                'categories' => ['pallet_racks'], 'brand' => 'regalis', 'kind' => 'quote', 'price' => 0, 'mode' => 'on_request', 'stock' => 'free',
                'attributes' => ['material' => 'steel', 'width' => '3600'],
                'images' => 1,
            ],

            // --- металопрокат: метри й кілограми, дробова кількість ---
            'MT-001' => [
                'categories' => ['metal'], 'brand' => 'stalform', 'price' => 98, 'stock' => 'in:600', 'unit' => 'm', 'min' => '1', 'step' => '0.5',
                'attributes' => ['material' => 'steel', 'weight' => '1.7'],
                'tiers' => [['min' => '100', 'price' => 89], ['min' => '1', 'price' => 85, 'group' => true]],
                'images' => 1,
            ],
            'MT-002' => [
                'categories' => ['metal'], 'brand' => 'stalform', 'price' => 142, 'stock' => 'in:300', 'unit' => 'm', 'min' => '2', 'step' => '0.5',
                'attributes' => ['material' => 'steel', 'weight' => '3.1'],
                'images' => 1,
            ],
            'MT-003' => [
                'categories' => ['metal'], 'price' => 38, 'stock' => 'free', 'unit' => 'kg', 'min' => '10', 'step' => '0.5',
                'attributes' => ['material' => 'steel'],
                'images' => 1,
            ],
            'MT-004' => [
                'categories' => ['metal'], 'brand' => 'stalform', 'price' => 3350, 'stock' => 'in:12',
                'attributes' => ['material' => 'steel', 'weight' => '49', 'width' => '1250'],
                'images' => 1,
            ],

            // --- інструмент: прості товари з числовими характеристиками ---
            'TL-D01' => ['categories' => ['drills'], 'brand' => 'toolmark', 'price' => 2450, 'stock' => 'in:9', 'attributes' => ['power' => '800', 'weight' => '2.3', 'cordless' => false], 'images' => 2],
            'TL-D02' => ['categories' => ['drills'], 'brand' => 'toolmark', 'price' => 3900, 'old' => 4300, 'stock' => 'in:7', 'attributes' => ['power' => '350', 'weight' => '1.4', 'cordless' => true], 'tiers' => [['min' => '1', 'price' => 3550, 'group' => true]], 'images' => 2],
            'TL-D03' => ['categories' => ['drills'], 'brand' => 'workline', 'price' => 2600, 'stock' => 'out', 'attributes' => ['power' => '250', 'weight' => '1.0', 'cordless' => true], 'images' => 1],
            'TL-D04' => ['categories' => ['drills'], 'brand' => 'workline', 'price' => 5200, 'stock' => 'in:4', 'attributes' => ['power' => '1050', 'weight' => '3.9', 'cordless' => false], 'tiers' => [['min' => '3', 'price' => 4950], ['min' => '1', 'price' => 4700, 'group' => true]], 'images' => 2],
            'TL-D05' => ['categories' => ['drills'], 'brand' => 'toolmark', 'price' => 4700, 'stock' => 'order:5', 'attributes' => ['power' => '1400', 'weight' => '4.6', 'cordless' => false], 'images' => 1],
            'TL-G01' => ['categories' => ['grinders'], 'brand' => 'toolmark', 'price' => 2150, 'stock' => 'in:14', 'attributes' => ['power' => '900', 'disc' => '125', 'weight' => '2.1', 'cordless' => false], 'images' => 2],
            'TL-G02' => ['categories' => ['grinders'], 'brand' => 'toolmark', 'price' => 4350, 'stock' => 'in:3', 'attributes' => ['power' => '2200', 'disc' => '230', 'weight' => '5.4', 'cordless' => false], 'images' => 1],
            'TL-G03' => ['categories' => ['grinders'], 'brand' => 'workline', 'price' => 5600, 'stock' => 'in:2', 'attributes' => ['power' => '700', 'disc' => '125', 'weight' => '2.4', 'cordless' => true], 'images' => 2],
            'TL-G04' => ['categories' => ['grinders'], 'brand' => 'workline', 'price' => 3100, 'old' => 3400, 'stock' => 'in:6', 'attributes' => ['power' => '1200', 'disc' => '125', 'weight' => '2.4', 'cordless' => false], 'images' => 1],
            'TL-H01' => ['categories' => ['hand_tools'], 'brand' => 'workline', 'price' => 1150, 'stock' => 'in:10', 'attributes' => ['material' => 'cr_v', 'weight' => '1.6'], 'images' => 1],
            'TL-H02' => ['categories' => ['hand_tools'], 'brand' => 'workline', 'price' => 320, 'stock' => 'free', 'attributes' => ['material' => 'steel', 'weight' => '0.6'], 'images' => 1],
            'TL-H03' => ['categories' => ['hand_tools'], 'brand' => 'toolmark', 'price' => 410, 'stock' => 'in:18', 'attributes' => ['material' => 'aluminium', 'weight' => '0.4'], 'images' => 1],
            'TL-H04' => ['categories' => ['hand_tools'], 'price' => 280, 'stock' => 'free', 'attributes' => ['material' => 'steel', 'weight' => '0.35'], 'images' => 1],
            'TL-H05' => ['categories' => ['hand_tools'], 'brand' => 'workline', 'price' => 390, 'stock' => 'in:40', 'attributes' => ['material' => 'cr_v', 'weight' => '0.5'], 'tiers' => [['min' => '10', 'price' => 350]], 'images' => 1],
        ];
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Runtime;

/**
 * Назва рушія й домівка — єдине джерело для підпису відповідей (Http\ResponseMarker), листів (Mail\MessageMarker) і фіду
 * Merchant (рішення власника 19.10.2026: у коді кожної сторінки має бути, що рушій наш, повною назвою). Не в шаблонах і не в
 * перекладах: тема чи пак не можуть її змінити. Частинами — щоб назва не була одним рядком для пошуку-заміни.
 */
final class BuildInfo
{
    private const string A = 'Lux';
    private const string B = 'Site';
    private const string C = 'Symfony';
    private const string VENDOR = self::A.self::B;
    private const string D = 'lux';
    private const string HOST = self::D.'site.agency';

    public const string NAME = self::C.' ecommerce '.self::VENDOR;
    public const string HOME = 'https://'.self::HOST;
    /** Коментар у HTML/XML: назва · домівка. */
    public const string LINE = self::NAME.' · '.self::HOME;
}

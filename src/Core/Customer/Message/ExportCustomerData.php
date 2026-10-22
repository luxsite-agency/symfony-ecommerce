<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Message;

/**
 * Експорт даних покупця у фоні (транспорт async): файл var/exports/<sha256 токена>.json, далі лист з одноразовим
 * посиланням (mailUrl уже містить токен і хост запиту; воркер хоста не знає). Токен Export виписано в контролері.
 */
final readonly class ExportCustomerData
{
    public function __construct(public int $customerId, public string $fileName, public string $mailUrl)
    {
    }
}

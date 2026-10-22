<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Messenger;

/**
 * Лист входу, реєстрації, підтвердження чи відновлення доступу (E12). Маршрутизується в окремий транспорт auth_mail з
 * власним воркером (messenger.yaml, docker-compose*): у спільній черзі async він чекав би за імпортом каталогу хвилини чи
 * години, а посилання з листа живе 15–60 хв (консиліум 07.10, розділ 3 п. 4).
 */
interface AuthMailMessage
{
}

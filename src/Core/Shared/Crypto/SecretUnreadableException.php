<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Crypto;

use RuntimeException;

/**
 * Конверт є, але відкрити його не можна: інший SETTINGS_SECRET_KEY (ключ змінено чи втрачено — відновлення з бекапу на
 * новому сервері), пошкоджені дані або ключа шифрування нема. Для застосунку це «секрет треба ввести знову», не 500.
 */
final class SecretUnreadableException extends RuntimeException
{
}

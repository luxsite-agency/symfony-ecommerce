<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

/**
 * Звідки взято ключі OAuth Google (рішення власника 13.10.2026: клієнт вводить їх у «Налаштуваннях магазину»; env
 * GOOGLE_OAUTH_* з .env.local — лише fallback для стендів агенції, коли перемикач в адмінці вимкнений).
 */
enum GoogleKeySource: string
{
    case Settings = 'settings';
    case Env = 'env';
    case None = 'none';
}

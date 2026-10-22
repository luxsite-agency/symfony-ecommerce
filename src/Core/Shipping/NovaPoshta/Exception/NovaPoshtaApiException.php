<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\Exception;

use RuntimeException;

/** API НП відповіло success=false або нечитабельно; повідомлення — errors[] з відповіді. Ретрай Messenger доречний. */
final class NovaPoshtaApiException extends RuntimeException
{
}

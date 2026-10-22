<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\Exception;

use LogicException;

/** Ключ NOVA_POSHTA_API_KEY порожній — довідники не синхронізуються, на checkout лишається текстове поле. */
final class NovaPoshtaDisabledException extends LogicException
{
}

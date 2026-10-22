<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Exception;

use App\Core\Shared\Translation\TranslatableDomainException;

/** Правило не можна зберегти: невалідна адреса, нема цілі, редирект на себе, цикл. Повідомлення — для людини (ключ error.redirect.*). */
final class InvalidRedirect extends TranslatableDomainException
{
}

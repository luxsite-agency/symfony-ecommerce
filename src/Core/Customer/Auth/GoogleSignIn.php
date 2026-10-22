<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Auth;

use App\Core\Customer\Entity\Customer;

/**
 * Вхід через Google для сайту — контракт між відгуками (агент D) і входом через Google (агент A, PR feat/google-sign-in):
 * внутрішня документація. Реалізація — `Site\Account\Google\GoogleSignInAdapter` (`#[AsAlias]`); гостю на
 * кешованій картці товару вона не торкається Security (CustomerContext).
 */
interface GoogleSignIn
{
    /** Магазин має ключі Google — кнопку «Увійти через Google» показувати. */
    public function isAvailable(): bool;

    /** Покупець прив'язаний до Google-акаунта (для app.reviews.require_google). */
    public function isLinked(Customer $customer): bool;

    /**
     * Відносний шлях початку входу через Google з поверненням на $next (шлях свого сайту) після успіху; залогіненому
     * без прив'язки — шлях прив'язки поточного акаунта. null — Google недоступний.
     */
    public function startPath(string $next, string $locale): ?string;
}

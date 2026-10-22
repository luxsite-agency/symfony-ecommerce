<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

use App\Core\Customer\Auth\GoogleSignIn;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Google\GoogleSignInConfig;
use App\Site\Customer\CustomerContext;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Вхід через Google для відгуків (контракт A↔D, внутрішня документація): замість заглушки
 * `GoogleSignInUnavailable` з #75. Доступність — ті самі ключі, що й `google_sign_in_enabled()`; шлях старту —
 * `site_account_google_start?next=` для гостя, а покупцю без прив'язки (app.reviews.require_google) — сторінка
 * прив'язки поточного кабінету. Гостя на кешованій картці товару визначає CustomerContext, не торкаючись Security.
 */
#[AsAlias(GoogleSignIn::class)]
final class GoogleSignInAdapter implements GoogleSignIn
{
    public function __construct(
        private readonly GoogleSignInConfig $config,
        private readonly CustomerContext $context,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->isEnabled();
    }

    public function isLinked(Customer $customer): bool
    {
        return $customer->isGoogleLinked();
    }

    public function startPath(string $next, string $locale): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $customer = $this->context->customer();
        if (null !== $customer && !$customer->isGoogleLinked()) {
            return $this->urls->generate('site_account_google_link', ['_locale' => $locale]);
        }

        return $this->urls->generate('site_account_google_start', ['next' => $next, '_locale' => $locale]);
    }
}

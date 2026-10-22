<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

use App\Core\Analytics\DataLayerCollector;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Google\GoogleSignInResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Після вдалого входу через Google: журнал security_audit (`google_signup` для нового, `google_linked` при прив'язці
 * за підтвердженою адресою, `google_placeholder_verified` — заготовка реєстрації стала акаунтом; сам `login via=google`
 * пише LoginAudit) і подія GA4 `sign_up` / `login` з method=google у чергу dataLayer: сторінки кабінету без тегів
 * (docs/analytics.md), подія вийде на першій сторінці сайту поза кабінетом (`next` або каталог).
 */
final class GoogleSignInListener
{
    public function __construct(private readonly AuthAudit $audit, private readonly DataLayerCollector $dataLayer)
    {
    }

    #[AsEventListener]
    public function __invoke(LoginSuccessEvent $event): void
    {
        $authenticator = $event->getAuthenticator();
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }
        if (!$authenticator instanceof GoogleAuthenticator) {
            return;
        }
        $result = $event->getPassport()->getAttribute(GoogleAuthenticator::PASSPORT_ATTRIBUTE);
        if (!$result instanceof GoogleSignInResult) {
            return;
        }
        $customer = $result->customer;
        if ($result->isNew) {
            $this->audit->log('google_signup', $customer->getEmail(), $customer->getId());
        } elseif ($result->verifiedPlaceholder) {
            $this->audit->log('google_placeholder_verified', $customer->getEmail(), $customer->getId());
        } elseif ($result->linkedNow) {
            $this->audit->log('google_linked', $customer->getEmail(), $customer->getId(), ['via' => 'login']);
        }
        $this->dataLayer->queue(['event' => $result->isNew ? 'sign_up' : 'login', 'method' => 'google']);
    }
}

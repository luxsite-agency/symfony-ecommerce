<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use RuntimeException;

/** Збій протоколу OIDC (обмін коду, підпис, claims): код причини — у журнал security_audit, покупцю — загальна помилка. */
final class OidcException extends RuntimeException
{
    /** Коди ?error= з RFC 6749 §4.1.2.1 і OIDC Core §3.1.2.6; решта — other (рев'ю #74, L3: значення контролює той, хто відкриває callback). */
    private const array PROVIDER_ERRORS = ['invalid_request', 'unauthorized_client', 'unsupported_response_type', 'invalid_scope', 'server_error', 'temporarily_unavailable', 'interaction_required', 'login_required', 'account_selection_required', 'consent_required'];

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct('' === $message ? $reason : $message);
    }

    /** Причина для журналу з ?error= від провайдера: access_denied → cancelled, відомі коди як є, невідоме → other. */
    public static function providerReason(string $error): string
    {
        return match (true) {
            'access_denied' === $error => 'cancelled',
            \in_array($error, self::PROVIDER_ERRORS, true) => $error,
            default => 'other',
        };
    }
}

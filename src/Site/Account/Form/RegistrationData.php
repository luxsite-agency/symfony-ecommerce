<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Реєстрація покупця: лише email. Імʼя й пароль задає власник скриньки на сторінці підтвердження з листа (VerifyEmailType):
 * інакше той, хто зареєстрував чужу адресу, задав би пароль за власника (рев'ю безпеки #45, п. 2).
 */
final class RegistrationData
{
    #[Assert\NotBlank(message: 'site.account.error.email_blank')]
    #[Assert\Email(message: 'site.account.error.email_invalid')]
    #[Assert\Length(max: Customer::EMAIL_MAX)]
    public string $email = '';

    /** Згода на обробку ПД для акаунта (обов'язкова; маркетинг — окремо в профілі, не умова реєстрації). */
    #[Assert\IsTrue(message: 'site.account.error.consent_required')]
    public bool $consent = false;
}

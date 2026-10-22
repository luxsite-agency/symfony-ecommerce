<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\Validator\Constraints as Assert;

/** Профіль: ім'я, телефон (E.164 після PhoneNormalizer), мова, згода на маркетинг. Email і пароль — окремі форми з повторною автентифікацією. */
final class ProfileData
{
    #[Assert\NotBlank(message: 'site.account.error.name_blank')]
    #[Assert\Length(max: Customer::NAME_MAX, maxMessage: 'site.account.wholesale.error.too_long')]
    public string $name = '';

    #[Assert\Length(max: 32, maxMessage: 'site.account.wholesale.error.too_long')]
    #[Assert\Regex(pattern: '/^[+\d][\d\s()-]{8,30}$/', message: 'site.account.error.phone_invalid')]
    public ?string $phone = null;

    public string $locale = '';

    public bool $marketing = false;

    public static function of(Customer $customer, bool $marketing): self
    {
        $data = new self();
        $data->name = $customer->getName();
        $data->phone = $customer->getPhone();
        $data->locale = $customer->getLocale();
        $data->marketing = $marketing;

        return $data;
    }
}

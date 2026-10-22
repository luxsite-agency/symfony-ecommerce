<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\TaxId;
use Symfony\Component\Validator\Constraints as Assert;

/** Поля форми «Стати оптовим клієнтом»; ключі помилок — site.account.wholesale.error.* */
final class WholesaleApplicationData
{
    #[Assert\NotBlank(message: 'site.account.wholesale.error.company_blank')]
    #[Assert\Length(max: Company::NAME_MAX, maxMessage: 'site.account.wholesale.error.too_long')]
    public string $companyName = '';

    #[Assert\NotBlank(message: 'site.account.wholesale.error.tax_id_blank')]
    #[Assert\Regex(pattern: TaxId::INPUT_PATTERN, message: 'site.account.wholesale.error.tax_id_invalid')]
    public string $taxId = '';

    public bool $vatPayer = false;

    #[Assert\NotBlank(message: 'site.account.wholesale.error.contact_blank')]
    #[Assert\Length(max: Company::CONTACT_MAX, maxMessage: 'site.account.wholesale.error.too_long')]
    public string $contactName = '';

    #[Assert\Length(max: Company::PHONE_MAX, maxMessage: 'site.account.wholesale.error.too_long')]
    public ?string $contactPhone = null;

    #[Assert\Length(max: WholesaleApplication::NOTE_MAX, maxMessage: 'site.account.wholesale.error.too_long')]
    public ?string $note = null;
}

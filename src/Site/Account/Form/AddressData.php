<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Order\Enum\ShippingMethod;
use Symfony\Component\Validator\Constraints as Assert;

/** Форма адреси: ті самі імена полів, що на checkout (shipping, city, address, np_city_ref, np_warehouse_ref) — працює той самий JS-пікер НП. */
final class AddressData
{
    #[Assert\Length(max: 60, maxMessage: 'site.account.wholesale.error.too_long')]
    public ?string $label = null;

    #[Assert\NotBlank(message: 'site.account.error.name_blank')]
    #[Assert\Length(max: 120, maxMessage: 'site.account.wholesale.error.too_long')]
    public string $recipientName = '';

    #[Assert\NotBlank(message: 'site.account.error.phone_invalid')]
    #[Assert\Regex(pattern: '/^[+\d][\d\s()-]{8,30}$/', message: 'site.account.error.phone_invalid')]
    public string $recipientPhone = '';

    public string $shipping = ShippingMethod::NovaPoshta->value;

    #[Assert\Length(max: 120, maxMessage: 'site.account.wholesale.error.too_long')]
    public ?string $city = null;

    #[Assert\Length(max: 255, maxMessage: 'site.account.wholesale.error.too_long')]
    public ?string $address = null;

    /** null, коли поля нема у формі (довідник НП вимкнено) */
    public ?string $npCityRef = '';

    public ?string $npWarehouseRef = '';

    public bool $isDefault = false;

    public static function of(CustomerAddress $address): self
    {
        $data = new self();
        $data->label = $address->getLabel();
        $data->recipientName = $address->getRecipientName();
        $data->recipientPhone = $address->getRecipientPhone();
        $data->shipping = $address->getShippingMethod()->value;
        $data->city = $address->getCity();
        $point = $address->getShippingPoint();
        $data->address = null === $point ? $address->getAddress() : null;
        $data->npCityRef = $point->cityRef ?? '';
        $data->npWarehouseRef = $point->warehouseRef ?? '';
        $data->isDefault = $address->isDefault();

        return $data;
    }
}

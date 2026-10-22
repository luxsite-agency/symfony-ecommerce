<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Демо-покупці публічного демо-магазину (рішення власника 11.10.2026): роздрібний, оптовий і заявник на опт з одним
 * відомим паролем з конфігу паку (`app.demo.customer_password`; порожньо = демо-режим вимкнено: покупці не сіються,
 * блоку «Демо-доступ» на сторінці входу нема). Адреси — на зарезервованому домені example.com, тож листи туди не йдуть
 * ніколи. Для цих акаунтів зміна пароля й email, видалення, експорт ПД і скидання пароля заблоковані (DemoAccountGuard).
 */
final class DemoAccounts
{
    public const string RETAIL = 'demo-retail@example.com';
    public const string WHOLESALE = 'demo-wholesale@example.com';
    public const string APPLICANT = 'demo-applicant@example.com';
    /** @var list<string> */
    public const array EMAILS = [self::RETAIL, self::WHOLESALE, self::APPLICANT];

    public function __construct(#[Autowire('%app.demo.customer_password%')] private readonly string $password)
    {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->password);
    }

    public function password(): string
    {
        return $this->password;
    }

    public function isDemoEmail(string $email): bool
    {
        return \in_array(Customer::normalizeEmail($email), self::EMAILS, true);
    }

    public function isDemo(?object $user): bool
    {
        return $user instanceof Customer && $this->isDemoEmail($user->getEmail());
    }

    /**
     * Для блоку «Демо-доступ»: ключ перекладу → email.
     *
     * @return array<string, string>
     */
    public function logins(): array
    {
        return ['retail' => self::RETAIL, 'wholesale' => self::WHOLESALE];
    }
}

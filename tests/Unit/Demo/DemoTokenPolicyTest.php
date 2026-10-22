<?php

declare(strict_types=1);

namespace App\Tests\Unit\Demo;

use App\Core\Customer\Entity\Customer;
use App\Core\Demo\DemoAccounts;
use App\Core\Demo\DemoTokenPolicy;
use PHPUnit\Framework\TestCase;

final class DemoTokenPolicyTest extends TestCase
{
    public function testDemoCustomersGetNoTokensOthersDo(): void
    {
        $policy = new DemoTokenPolicy(new DemoAccounts('secret'));

        self::assertFalse($policy->issuesTokensFor(new Customer(DemoAccounts::RETAIL, 'Демо', 'uk')));
        self::assertFalse($policy->issuesTokensFor(new Customer(strtoupper(DemoAccounts::WHOLESALE), 'Демо', 'uk')), 'регістр адреси не важливий');
        self::assertTrue($policy->issuesTokensFor(new Customer('buyer@example.org', 'Покупець', 'uk')));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\AdminApi;

use App\Core\AdminApi\ApiScope;
use App\Core\Security\Enum\UserRole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApiScopeTest extends TestCase
{
    public function testFromValuesDedupesAndRejectsUnknown(): void
    {
        self::assertSame([ApiScope::CatalogRead, ApiScope::PricesWrite], ApiScope::fromValues(['catalog:read', 'prices:write', 'catalog:read']));
        $this->expectException(InvalidArgumentException::class);
        ApiScope::fromValues(['orders:read']);
    }

    public function testEveryScopeNeedsManagerRoleAndHasLabel(): void
    {
        foreach (ApiScope::cases() as $scope) {
            self::assertSame(UserRole::Manager, $scope->requiredRole(), $scope->value);
            self::assertNotSame('', $scope->label());
        }
        self::assertSame(['catalog:read', 'content:write', 'prices:write', 'theme:write'], array_values(ApiScope::choices()));
    }
}

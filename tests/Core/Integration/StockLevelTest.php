<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration;

use App\Core\Integration\Crm\StockLevel;
use PHPUnit\Framework\TestCase;

final class StockLevelTest extends TestCase
{
    public function testAvailableIsInStockMinusReserve(): void
    {
        $level = new StockLevel('A-1', '10', '3', '1017');
        self::assertSame('7.000', $level->available);
        self::assertFalse($level->clamped);
        self::assertSame('1017', $level->externalId);
    }

    public function testNegativeIsClampedToZero(): void
    {
        $level = new StockLevel('A-1', '2', '5');
        self::assertSame('0.000', $level->available);
        self::assertTrue($level->clamped);
    }

    public function testDecimalQuantities(): void
    {
        self::assertSame('1.250', (new StockLevel('M-1', '2.5', '1,25'))->available);
    }
}

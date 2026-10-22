<?php

declare(strict_types=1);

namespace App\Tests\Site\Catalog;

use App\Site\Catalog\PageWindow;
use App\Site\Catalog\VariantMatrix;
use PHPUnit\Framework\TestCase;

/** Перемикач варіантів за осями і вікно пагінації — чиста логіка без ядра. */
final class VariantMatrixTest extends TestCase
{
    private VariantMatrix $matrix;

    protected function setUp(): void
    {
        // 1: red/38, 2: red/42 (нема), 3: blue/42, 4: blue/44
        $this->matrix = new VariantMatrix(
            [['code' => 'color', 'options' => ['red', 'blue']], ['code' => 'size', 'options' => ['s38', 's42', 's44']]],
            [
                ['key' => 1, 'axes' => ['color' => 'red', 'size' => 's38'], 'available' => true],
                ['key' => 2, 'axes' => ['color' => 'red', 'size' => 's42'], 'available' => false],
                ['key' => 3, 'axes' => ['color' => 'blue', 'size' => 's42'], 'available' => true],
                ['key' => 4, 'axes' => ['color' => 'blue', 'size' => 's44'], 'available' => true],
            ],
        );
    }

    public function testSelect(): void
    {
        self::assertSame(1, $this->matrix->select([]));
        self::assertSame(2, $this->matrix->select(['color' => 'red', 'size' => 's42']), 'точний збіг, хоч і недоступний');
        self::assertSame(3, $this->matrix->select(['size' => 's42']), 'рівні збіги — доступний');
        self::assertSame(4, $this->matrix->select(['size' => 's44']));
        self::assertSame(1, $this->matrix->select(['size' => 's44', 'color' => 'red']), 'по одному збігу в 1, 2, 4 — перший доступний');
        self::assertSame(1, $this->matrix->select(['size' => 'nosuch']));
    }

    public function testChoicesKeepOtherAxes(): void
    {
        $choices = $this->matrix->choices(3); // blue/42
        self::assertSame(['color', 'size'], array_column($choices, 'code'));

        $colors = array_column($choices[0]['options'], null, 'value');
        self::assertSame(['color' => 'red', 'size' => 's42'], $colors['red']['target'], 'red того ж розміру існує');
        self::assertFalse($colors['red']['available']);
        self::assertTrue($colors['red']['exact']);
        self::assertTrue($colors['blue']['selected']);

        $sizes = array_column($choices[1]['options'], null, 'value');
        self::assertSame(['color' => 'red', 'size' => 's38'], $sizes['s38']['target'], '38 синього нема — веде на red/38');
        self::assertFalse($sizes['s38']['exact']);
        self::assertSame(['color' => 'blue', 'size' => 's44'], $sizes['s44']['target']);
        self::assertTrue($sizes['s44']['exact']);
    }

    public function testNoAxes(): void
    {
        $matrix = new VariantMatrix([], [['key' => 7, 'axes' => [], 'available' => false]]);
        self::assertSame(7, $matrix->select([]));
        self::assertSame([], $matrix->choices(7));
        self::assertNull((new VariantMatrix([], []))->select([]));
    }

    public function testPageWindow(): void
    {
        self::assertSame([], PageWindow::pages(1, 1));
        self::assertSame([1, 2, 3], PageWindow::pages(1, 3));
        self::assertSame([1, 2, 3, null, 10], PageWindow::pages(1, 10));
        self::assertSame([1, null, 4, 5, 6, 7, 8, null, 20], PageWindow::pages(6, 20));
        self::assertSame([1, 2, 3, 4, 5, 6], PageWindow::pages(4, 6), 'розрив на одну сторінку показує саму сторінку');
        self::assertSame([1, null, 18, 19, 20], PageWindow::pages(99, 20), 'поточна за межами — притиснута до останньої');
    }
}

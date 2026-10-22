<?php

declare(strict_types=1);

namespace App\Tests\Unit\Demo;

use App\Core\Demo\DemoPhotos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** DemoPhotos::find: файл за ключем у теці паку; симлінк (навіть на справжнє фото) не приймається. */
final class DemoPhotosTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/demo-images';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/demo-photos-unit-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFindsRegularFileAnyCase(): void
    {
        copy(self::FIXTURES.'/DV-P01-1.jpg', $this->dir.'/dv-p01-1.jpg');
        copy(self::FIXTURES.'/category-dveri.jpg', $this->dir.'/category-dveri.jpg');
        $photos = new DemoPhotos($this->dir);

        self::assertSame($this->dir.'/dv-p01-1.jpg', $photos->product('DV-P01', 1));
        self::assertSame($this->dir.'/category-dveri.jpg', $photos->category('dveri'));
        self::assertNull($photos->product('DV-P01', 2));
    }

    public function testSymlinkIsIgnored(): void
    {
        symlink((string) realpath(self::FIXTURES.'/DV-P01-1.jpg'), $this->dir.'/DV-P01-1.jpg');

        self::assertNull((new DemoPhotos($this->dir))->product('DV-P01', 1));
    }

    public function testMissingDirectory(): void
    {
        $photos = new DemoPhotos($this->dir.'/nope');

        self::assertFalse($photos->isAvailable());
        self::assertNull($photos->post('post1'));
    }
}

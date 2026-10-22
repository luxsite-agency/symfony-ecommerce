<?php

declare(strict_types=1);

namespace App\Tests\Core\License;

use App\Core\License\EngineRelease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class EngineReleaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/shop-release-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testReadsStoreReleaseFile(): void
    {
        file_put_contents($this->dir.'/STORE_RELEASE', "key=shop1\nengine_ref=v1.2.0\nengine_sha=0123456789ab\npack_hash=deadbeef\npack_git=abc\nbuilt_at=2026-10-11T12:30:00+03:00\nbuilt_by=deploy@build-host\n\nbroken line\n");
        $release = new EngineRelease($this->dir);
        self::assertSame('0123456789ab', $release->sha());
        self::assertSame('v1.2.0', $release->ref());
        self::assertSame('deadbeef', $release->packHash());
        self::assertSame('2026-10-11T12:30:00+03:00', $release->builtAt()?->format(\DATE_ATOM));
        self::assertSame('shop1', $release->all()['key']);
    }

    public function testRefEqualToShaOrHeadIsHidden(): void
    {
        file_put_contents($this->dir.'/STORE_RELEASE', "engine_ref=HEAD\nengine_sha=0123456789ab\nbuilt_at=not-a-date\n");
        $release = new EngineRelease($this->dir);
        self::assertNull($release->ref());
        self::assertNull($release->builtAt());
        file_put_contents($this->dir.'/STORE_RELEASE', "engine_ref=0123456789ab\nengine_sha=0123456789ab\n");
        self::assertNull((new EngineRelease($this->dir))->ref());
    }

    public function testWithoutFileAndGitIsUnknown(): void
    {
        $release = new EngineRelease($this->dir);
        self::assertNull($release->sha());
        self::assertNull($release->ref());
        self::assertSame([], $release->all());
    }
}

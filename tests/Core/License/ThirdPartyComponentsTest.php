<?php

declare(strict_types=1);

namespace App\Tests\Core\License;

use App\Core\License\ThirdPartyComponents;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class ThirdPartyComponentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/shop-license-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'zeta/lib', 'version' => 'v2.0.0', 'license' => ['MIT']],
                ['name' => 'acme/dual', 'version' => '1.2.3', 'license' => ['GPL-2.0-or-later', 'MIT']],
                ['name' => 'acme/gpl-only', 'version' => '0.1', 'license' => ['LGPL-3.0-only']],
                ['name' => 'acme/unlicensed', 'version' => '9'],
            ],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '13.0', 'license' => ['BSD-3-Clause']]],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRuntimePackagesSortedWithLicensesAndCachedInFile(): void
    {
        $file = $this->dir.'/var/license/components.json';
        $components = new ThirdPartyComponents($file, $this->dir, new NullLogger());
        $all = $components->all();
        self::assertSame(['acme/dual', 'acme/gpl-only', 'acme/unlicensed', 'zeta/lib'], array_column($all, 'name'), 'лише runtime-пакети, за назвою');
        self::assertSame(['name' => 'acme/dual', 'version' => '1.2.3', 'license' => 'GPL-2.0-or-later / MIT'], $all[0]);
        self::assertSame('невідомо', $all[2]['license']);
        self::assertFileExists($file, 'перелік записано, щоб не парсити lock щоразу');

        // той самий composer.lock → читається файл, не lock (підміняємо вміст файлу маркером)
        $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $data['components'] = [['name' => 'from/file', 'version' => '1', 'license' => 'MIT']];
        file_put_contents($file, json_encode($data, \JSON_THROW_ON_ERROR));
        self::assertSame('from/file', (new ThirdPartyComponents($file, $this->dir, new NullLogger()))->all()[0]['name']);

        // інший composer.lock (розмір) → перелік перераховано і файл перезаписано
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'only/one', 'version' => '1', 'license' => ['MIT']]], 'packages-dev' => []], \JSON_THROW_ON_ERROR));
        $fresh = (new ThirdPartyComponents($file, $this->dir, new NullLogger()))->all();
        self::assertSame(['only/one'], array_column($fresh, 'name'));
        self::assertStringContainsString('only/one', (string) file_get_contents($file));
    }

    public function testBrokenCacheFileIsIgnored(): void
    {
        $file = $this->dir.'/components.json';
        file_put_contents($file, '{not json');
        self::assertCount(4, (new ThirdPartyComponents($file, $this->dir, new NullLogger()))->all());
    }

    /** Файл не пишеться (read-only var/) → перелік тримає cache.app за відбитком lock, composer.lock не розбирається щоразу. */
    public function testUnwritableFileFallsBackToCache(): void
    {
        $file = $this->dir.'/composer.lock/components.json'; // шлях під звичайним файлом — dumpFile падає
        $cache = new ArrayAdapter();
        self::assertSame('zeta/lib', (new ThirdPartyComponents($file, $this->dir, new NullLogger(), new Filesystem(), $cache))->all()[3]['name']);
        self::assertFileDoesNotExist($file);

        // той самий відбиток (розмір і mtime), інший вміст: зі спільним кешем — старий перелік, з порожнім — новий
        $lock = $this->dir.'/composer.lock';
        $mtime = (int) filemtime($lock);
        file_put_contents($lock, str_replace('zeta\\/lib', 'zeta\\/lia', (string) file_get_contents($lock)));
        touch($lock, $mtime);
        clearstatcache();
        self::assertSame('zeta/lib', (new ThirdPartyComponents($file, $this->dir, new NullLogger(), new Filesystem(), $cache))->all()[3]['name']);
        self::assertSame('zeta/lia', (new ThirdPartyComponents($file, $this->dir, new NullLogger(), new Filesystem(), new ArrayAdapter()))->all()[3]['name']);
    }

    public function testCopyleftDetection(): void
    {
        $components = (new ThirdPartyComponents($this->dir.'/c.json', $this->dir, new NullLogger()))->fromComposerLock();
        self::assertSame(['acme/gpl-only', 'acme/unlicensed'], array_column(ThirdPartyComponents::copyleft($components), 'name'), 'MIT-або-GPL — не copyleft, без ліцензії — під питанням');
    }

    /** Політика рушія: серед runtime-залежностей (composer.lock репозиторію) нема GPL/LGPL/AGPL і пакетів без ліцензії. */
    public function testEngineRuntimeDependenciesHaveNoCopyleftLicenses(): void
    {
        $root = \dirname(__DIR__, 3);
        $components = (new ThirdPartyComponents($this->dir.'/engine.json', $root, new NullLogger()))->fromComposerLock();
        self::assertGreaterThan(50, \count($components));
        self::assertSame([], ThirdPartyComponents::copyleft($components), 'runtime-пакет з copyleft-ліцензією або без ліцензії: '.json_encode(ThirdPartyComponents::copyleft($components), \JSON_UNESCAPED_SLASHES));
        $names = array_column($components, 'name');
        self::assertContains('symfony/framework-bundle', $names);
        self::assertNotContains('phpunit/phpunit', $names, 'dev-пакети не входять');
    }
}

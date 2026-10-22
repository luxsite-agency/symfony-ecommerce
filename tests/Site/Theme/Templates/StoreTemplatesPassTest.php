<?php

declare(strict_types=1);

namespace App\Tests\Site\Theme\Templates;

use App\Site\Theme\Templates\StoreTemplates;
use App\Site\Theme\Templates\StoreTemplatesPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Loader\FilesystemLoader;

/**
 * Лоадер шаблонів паку (E14): тека templates/store/ стає першою в головному namespace лише коли існує; не-twig, шлях поза
 * site/** і заборонена зона валять компіляцію контейнера (а отже збірку образу), а не прод.
 */
final class StoreTemplatesPassTest extends TestCase
{
    private const string FIXTURE = 'tests/store/pack-demo/themes/demo/templates';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/store-templates-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testMissingDirectoryAddsNothing(): void
    {
        $container = $this->container($this->tmp.'/nope');
        (new StoreTemplatesPass())->process($container);

        self::assertSame([], $container->getDefinition(StoreTemplatesPass::LOADER)->getMethodCalls(), 'нема теки — FilesystemLoader не чіпаємо (addPath на неіснуючу теку кидав би LoaderError)');
    }

    public function testFixturePackTemplatesArePrependedToMainNamespace(): void
    {
        $dir = self::root().'/'.self::FIXTURE;
        $container = $this->container('%kernel.project_dir%/'.self::FIXTURE);
        (new StoreTemplatesPass())->process($container);

        self::assertSame([['prependPath', [$dir]]], $container->getDefinition(StoreTemplatesPass::LOADER)->getMethodCalls(), 'параметр розкрито, тека паку — першою (prependPath), namespace головний');
    }

    /** @return iterable<string, array{string, string}> */
    public static function violations(): iterable
    {
        yield 'заборонена зона: кабінет' => ['site/account/login.html.twig', 'заборонена зона'];
        yield 'заборонена зона: schema' => ['site/partials/_schema.html.twig', 'заборонена зона'];
        yield 'заборонена зона: sitemap' => ['site/sitemap.xml.twig', 'заборонена зона'];
        yield 'поза site/' => ['admin/dashboard.html.twig', 'перекривати можна лише site/**'];
        yield 'листи' => ['emails/order/customer.html.twig', 'перекривати можна лише site/**'];
        yield 'не twig' => ['site/hack.php', 'лише *.twig'];
    }

    #[DataProvider('violations')]
    public function testViolationsFailContainerCompilation(string $file, string $message): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->tmp.'/site/home.html.twig', "{% extends '@engine/site/home.html.twig' %}");
        $fs->dumpFile($this->tmp.'/'.$file, 'x');

        $violations = StoreTemplates::violations($this->tmp);
        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringContainsString($message, $violations[0]);
        self::assertStringStartsWith($file.': ', $violations[0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($file);
        (new StoreTemplatesPass())->process($this->container($this->tmp));
    }

    /** Обхід забороненої зони лінком: site/account -> ../_acc — ітератор без FOLLOW_SYMLINKS лінк не розкриває, збірка зберігає. */
    public function testSymlinksAreRejected(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->tmp.'/_acc/index.html.twig', 'hijack');
        $fs->dumpFile($this->tmp.'/site/partials/_real.html.twig', 'ok');
        if (!@symlink('../_acc', $this->tmp.'/site/account') || !@symlink('_real.html.twig', $this->tmp.'/site/partials/_link.html.twig')) {
            self::markTestSkipped('symlink недоступний у цьому середовищі');
        }

        $violations = StoreTemplates::violations($this->tmp);
        self::assertSame([
            '_acc/index.html.twig: перекривати можна лише site/**',
            'site/account: символьне посилання в шаблонах паку заборонене',
            'site/partials/_link.html.twig: символьне посилання в шаблонах паку заборонене',
        ], $violations);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('site/account: символьне посилання');
        (new StoreTemplatesPass())->process($this->container($this->tmp));
    }

    public function testValidThemeDirectoryHasNoViolations(): void
    {
        (new Filesystem())->dumpFile($this->tmp.'/site/partials/_my.html.twig', '<p>ok</p>');
        (new Filesystem())->dumpFile($this->tmp.'/site/catalog/product_show.html.twig', "{% extends '@engine/site/catalog/product_show.html.twig' %}");

        self::assertSame([], StoreTemplates::violations($this->tmp));
        self::assertSame([], StoreTemplates::violations(self::root().'/'.self::FIXTURE), 'фікстурна тема паку валідна');
    }

    private function container(string $parameter): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', self::root());
        $container->setParameter(StoreTemplatesPass::PARAMETER, $parameter);
        $container->register(StoreTemplatesPass::LOADER, FilesystemLoader::class);

        return $container;
    }

    private static function root(): string
    {
        return str_replace('\\', '/', \dirname(__DIR__, 4));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Site\Theme;

use App\Site\Theme\Command\StoreValidateCommand;
use App\Site\Theme\SiteTheme;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/** store:validate — тема розгортання: невідомі ключі й відсутні файли тем ламають перевірку; --schema віддає схему для паків. */
final class StoreValidateCommandTest extends KernelTestCase
{
    public function testDefaultThemeOfTestKernelIsValidAndSchemaIsJson(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('store:validate'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('ключі й файли в порядку', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--schema' => true]));
        self::assertSame(SiteTheme::schema(), json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testUnknownKeysAndMissingFilesFail(): void
    {
        $public = sys_get_temp_dir().'/store-validate-'.bin2hex(random_bytes(4));
        mkdir($public.'/themes/brand', 0o777, true);
        touch($public.'/themes/brand/logo.webp');
        try {
            $command = new StoreValidateCommand([
                'key' => 'brand',
                'logo' => ['src' => 'themes/brand/logo.webp'],
                'hero' => ['image_srcset' => [1280 => 'themes/brand/hero-1280.webp'], 'titel' => 'Мерч'],
            ], $public);
            $output = new BufferedOutput();

            self::assertSame(Command::FAILURE, $command(new SymfonyStyle(new ArrayInput([]), $output)));
            $display = preg_replace('/\s+/', ' ', $output->fetch());
            self::assertStringContainsString('hero.titel: невідомий ключ', $display);
            self::assertStringContainsString('hero.image_srcset.1280: нема файлу public/themes/brand/hero-1280.webp', $display);
            self::assertStringNotContainsString('logo.src', $display);
        } finally {
            array_map(unlink(...), [$public.'/themes/brand/logo.webp']);
            rmdir($public.'/themes/brand');
            rmdir($public.'/themes');
            rmdir($public);
        }
    }
}

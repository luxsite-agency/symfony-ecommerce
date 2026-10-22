<?php

declare(strict_types=1);

namespace App\Tests\Site\Theme;

use App\Core\Cart\CartResolver;
use App\Core\Customer\CurrentCustomer;
use App\Site\Theme\SiteTheme;
use App\Site\Twig\ThemeExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/** Тема сайту з параметра app.theme: злиття з дефолтами, тексти за мовою, безпечні CSS-змінні, шрифти без preload, лого обов'язкове. */
final class SiteThemeTest extends TestCase
{
    private const array LOGO = ['logo' => ['src' => 'themes/x/logo.svg']];

    /** @param array<string, mixed> $theme */
    private static function theme(array $theme = []): SiteTheme
    {
        return new SiteTheme($theme + self::LOGO);
    }

    public function testDefaultsAreNeutralLightTheme(): void
    {
        $theme = self::theme();

        self::assertSame('light', $theme->get('color_scheme'));
        self::assertSame('#ffffff', $theme->get('colors.bg'));
        self::assertSame('themes/x/logo.svg', $theme->get('logo.src'));
        self::assertSame(40, $theme->get('logo.width'), 'розмір лого — дефолт, коли задано лише src');
        self::assertFalse($theme->get('support.enabled'));
        self::assertNull($theme->fontFamily());
        self::assertSame([], $theme->fontFaces());
        self::assertStringStartsWith('system-ui', $theme->cssVariables()['--font']);
    }

    public function testParameterOverridesOnlyGivenKeysAndReplacesLists(): void
    {
        $theme = self::theme([
            'colors' => ['bg' => '#0b0f13', 'accent_bg' => 'linear-gradient(90deg,#4a0211 0%,#b62f2f 100%)'],
            'footer' => ['socials' => [['name' => 'Instagram', 'url' => '#']]],
            'font' => ['family' => 'UAFSans', 'faces' => [700 => 'themes/x/b.woff2', 400 => 'themes/x/r.woff2']],
        ]);

        self::assertSame('#0b0f13', $theme->get('colors.bg'));
        self::assertSame('#1a1f24', $theme->get('colors.ink'), 'незадані кольори — дефолт');
        self::assertSame([['name' => 'Instagram', 'url' => '#']], $theme->get('footer.socials'));
        self::assertSame([400 => 'themes/x/r.woff2', 700 => 'themes/x/b.woff2'], $theme->fontFaces());
        $vars = $theme->cssVariables();
        self::assertSame('linear-gradient(90deg,#4a0211 0%,#b62f2f 100%)', $vars['--c-accent-bg']);
        self::assertStringStartsWith('"UAFSans", system-ui', $vars['--font']);
    }

    /** Широкі екрани (15.10.2026): гумовий контейнер до 1620 px; ширина з теми паку перемагає. */
    public function testContainerIsFluid1620UnlessThemeSetsIt(): void
    {
        self::assertArrayNotHasKey('--container', self::theme()->cssVariables());
        self::assertStringContainsString('max-width:var(--container,1620px)', (string) file_get_contents(\dirname(__DIR__, 3).'/public/assets/site.css'));
        self::assertSame('1200px', self::theme(['container' => '1200px'])->cssVariables()['--container']);
    }

    public function testTextsByLocaleWithFallback(): void
    {
        $theme = self::theme(['hero' => ['title' => ['uk' => 'Мерч', 'en' => 'Store'], 'text' => 'Один текст']]);

        self::assertSame('Store', $theme->text('hero.title', 'en', 'uk'));
        self::assertSame('Мерч', $theme->text('hero.title', 'ru', 'uk'), 'нема мови — мова за замовчуванням');
        self::assertSame('Один текст', $theme->text('hero.text', 'en', 'uk'));
        self::assertNull($theme->text('hero.eyebrow', 'uk', 'uk'));
        self::assertSame('Store', SiteTheme::localize(['en' => 'Store'], 'ru', 'uk'), 'нема ні мови, ні дефолту — перша непорожня');
    }

    public function testUnsafeCssValuesAreDropped(): void
    {
        $vars = self::theme(['colors' => ['bg' => 'red;}</style><script>', 'ink' => '#000'], 'radius' => '4px{'])->cssVariables();

        self::assertArrayNotHasKey('--c-bg', $vars);
        self::assertArrayNotHasKey('--radius', $vars);
        self::assertSame('#000', $vars['--c-ink']);
    }

    public function testStyleHasSwapFontFacesAndRootVariablesWithoutPreload(): void
    {
        $theme = self::theme([
            'color_scheme' => 'dark',
            'font' => ['family' => 'UAFSans', 'faces' => [400 => 'themes/x/r.woff2']],
            'placeholder' => 'themes/x/emblem.webp',
        ]);
        $requests = new RequestStack();
        $requests->push(Request::create('/'));
        $extension = new ThemeExtension(
            $theme,
            new Packages(new Package(new EmptyVersionStrategy())),
            new CartResolver($this->createStub(EntityManagerInterface::class), $requests, $this->createStub(CurrentCustomer::class), 'UAH', $this->createStub(ManagerRegistry::class)),
            $requests,
            'uk',
            sys_get_temp_dir(),
        );

        $style = (string) $extension->style();
        self::assertStringContainsString('@font-face{font-family:"UAFSans";font-style:normal;font-weight:400;font-display:swap;src:url("themes/x/r.woff2") format("woff2")}', $style);
        self::assertStringContainsString('--ph-image:url("themes/x/emblem.webp");', $style);
        self::assertStringContainsString('color-scheme:dark', $style);
        self::assertStringNotContainsString('preload', $style);
        self::assertSame(0, $extension->cartCount(), 'без cookie кошика — 0 і без запиту в БД');
    }

    public function testUnknownThemeKeysFailInsteadOfBeingSwallowed(): void
    {
        $errors = SiteTheme::validate([
            'colours' => [],
            'colors' => ['acent' => '#000', 'bg' => '#fff'],
            'hero' => ['title' => ['uk' => 'Мерч'], 'image_srcset' => [1280 => 'themes/x/h.webp'], 'cta' => 'До каталогу'],
            'font' => ['faces' => [400 => 'themes/x/r.woff2']],
            'footer' => 'текст замість мапи',
        ]);

        self::assertCount(4, $errors, implode("\n", $errors));
        self::assertStringStartsWith('colours: невідомий ключ', $errors[0]);
        self::assertStringStartsWith('colors.acent: невідомий ключ', $errors[1]);
        self::assertStringStartsWith('hero.cta: невідомий ключ', $errors[2]);
        self::assertSame('footer: очікується мапа ключів', $errors[3]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('colors.acent');
        new SiteTheme(['colors' => ['acent' => '#000']] + self::LOGO);
    }

    /** Правило власника 09.10.2026: лого в шапці на кожному сайті — тема без logo.src не збирається. */
    public function testLogoIsRequired(): void
    {
        foreach ([[], ['logo' => ['width' => 40]], ['logo' => ['src' => null]], ['logo' => ['src' => ' ']]] as $theme) {
            $errors = SiteTheme::validate($theme);
            self::assertCount(1, $errors, json_encode($theme, \JSON_THROW_ON_ERROR));
            self::assertStringStartsWith('logo.src: обов', $errors[0]);
        }
        self::assertSame([], SiteTheme::validate(self::LOGO));
        self::assertSame([], SiteTheme::validate(['logo' => ['src' => 'assets/logo.svg', 'width' => 40, 'height' => 40]]), 'дефолт рушія з config/services.yaml');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('logo.src');
        new SiteTheme();
    }

    public function testEngineDefaultThemeInServicesYamlHasLogoFile(): void
    {
        $root = \dirname(__DIR__, 3);
        $theme = Yaml::parseFile($root.'/config/services.yaml')['parameters']['app.theme'];
        self::assertSame([], SiteTheme::validate($theme), 'дефолтна тема рушія (dev, тести) має проходити валідацію');
        self::assertFileExists($root.'/public/'.$theme['logo']['src'], 'нейтральний знак рушія для розгортання без паку');
    }

    public function testShippedThemesMatchSchema(): void
    {
        foreach (glob(\dirname(__DIR__, 3).'/config/store/themes/*.yaml.dist') ?: [] as $file) {
            $theme = Yaml::parseFile($file)['parameters']['app.theme'];
            self::assertSame([], SiteTheme::validate($theme), basename($file));
        }
        $schema = SiteTheme::schema();
        self::assertNull($schema['key'], 'скаляр — вільне значення');
        self::assertNull($schema['font']['faces'], 'мапа вага => файл — вільна');
        self::assertArrayHasKey('accent_bg', $schema['colors']);
    }

    public function testSchemaFileForPacksIsInSyncWithDefaults(): void
    {
        $file = \dirname(__DIR__, 3).'/config/store/theme-schema.json';
        self::assertFileExists($file);
        self::assertSame(
            SiteTheme::schema(),
            json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR),
            'config/store/theme-schema.json застарів: bin/console store:validate --schema > config/store/theme-schema.json',
        );
    }
}

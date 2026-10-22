<?php

declare(strict_types=1);

namespace App\Tests\Site\Theme\Templates;

use App\Site\Theme\Templates\StoreTemplates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Контракт шаблонів для тем паків (docs/themes.md, template_contract): блоки базового шаблону, partial-и шапки/футера з переліком
 * змінних, коментар-контракт у кожному публічному шаблоні, одна версія контракту й ті самі правила в PHP (StoreTemplates),
 * python (bin/store.d/_validate.py) і документації.
 */
final class TemplateContractTest extends TestCase
{
    public const array BASE_BLOCKS = ['title', 'metaDescription', 'ogType', 'ogImage', 'schema', 'head', 'bodyClass', 'header', 'header_actions', 'nav', 'body', 'footer'];
    private const array PARTIALS = ['_header', '_header_actions', '_nav', '_footer'];

    public function testBaseDefinesEveryContractBlock(): void
    {
        $base = self::read('templates/site/base.html.twig');
        foreach (self::BASE_BLOCKS as $block) {
            self::assertMatchesRegularExpression('/\{%-?\s*block '.$block.'\s*-?%\}/', self::withoutComments($base), "базовий шаблон має блок {$block}");
        }
        foreach (['analytics_head()', 'analytics_body_start()', 'analytics_body_end()', 'schema.website()', 'rel="canonical"', 'name="referrer"', 'site_theme_style()'] as $sensitive) {
            self::assertStringContainsString($sensitive, $base, 'чутлива зона лишається в базовому шаблоні рушія');
        }
    }

    public function testBodyClassBlocksStartWithSpace(): void
    {
        // base: class="theme-{{ key }}{% block bodyClass %}…" — без пробілу клас склеюється («theme-demopage-solution») і тема не діє
        $files = glob(self::root().'/templates/site/{,*/,*/*/}*.html.twig', \GLOB_BRACE) ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\{%-?\s*block bodyClass\s*-?%\}[^\s{]/', self::withoutComments((string) file_get_contents($file)), basename($file).': bodyClass починається з пробілу');
        }
    }

    public function testHeaderAndFooterPartialsDocumentTheirVariables(): void
    {
        foreach (self::PARTIALS as $partial) {
            $source = self::read('templates/site/partials/'.$partial.'.html.twig');
            self::assertStringStartsWith('{#', $source, "{$partial}: перший рядок — коментар контракту");
            self::assertStringContainsString('Змінні', $source, "{$partial}: перелік вхідних змінних");
            self::assertStringContainsString('Стабільні класи', $source, "{$partial}: перелік стабільних CSS-класів");
        }
        self::assertStringContainsString("include 'site/partials/_header.html.twig'", self::read('templates/site/base.html.twig'));
        self::assertStringContainsString("include 'site/partials/_footer.html.twig'", self::read('templates/site/base.html.twig'));
        self::assertStringContainsString('brand-logo', self::read('templates/site/partials/_header.html.twig'), 'лого в шапці — правило власника');
        self::assertStringContainsString("{uk: 'UA'}", self::read('templates/site/partials/_footer.html.twig'), 'перемикач мов текстом, uk → UA');
    }

    public function testEveryOverridablePageTemplateCarriesContractComment(): void
    {
        $missing = [];
        foreach (self::pageTemplates() as $rel) {
            $source = self::read('templates/'.$rel);
            if (!str_contains($source, 'Контракт теми') || !str_contains($source, 'template_contract '.StoreTemplates::CONTRACT)) {
                $missing[] = $rel;
            }
        }
        self::assertSame([], $missing, 'публічні шаблони без коментаря «Контракт теми … template_contract N» (змінні контролера для тем паків)');
    }

    public function testContractVersionAndRulesAreTheSameInPhpPythonAndDocs(): void
    {
        $python = self::read('bin/store.d/_validate.py');
        self::assertMatchesRegularExpression('/^TEMPLATE_CONTRACT = '.StoreTemplates::CONTRACT.'$/m', $python, '_validate.py: TEMPLATE_CONTRACT = StoreTemplates::CONTRACT');
        self::assertMatchesRegularExpression('/^TEMPLATE_CONTRACT=1$/m', self::read('bin/store.d/theme.sh'), 'theme.sh пише contract маніфесту');
        preg_match('/^TEMPLATE_ALLOWED = \((.*)\)$/m', $python, $m);
        self::assertSame(StoreTemplates::ALLOWED, self::tuple($m[1] ?? ''), 'дозволені теки однакові в PHP і python');
        preg_match('/^TEMPLATE_FORBIDDEN = \((.*)\)$/m', $python, $m);
        self::assertSame(StoreTemplates::FORBIDDEN, self::tuple($m[1] ?? ''), 'заборонена зона однакова в PHP і python');

        $docs = self::read('docs/themes.md');
        self::assertStringContainsString('template_contract: '.StoreTemplates::CONTRACT, $docs, 'docs/themes.md оголошує ту саму версію контракту');
        foreach (self::BASE_BLOCKS as $block) {
            self::assertStringContainsString('`'.$block.'`', $docs, "docs/themes.md описує блок {$block}");
        }
        foreach (StoreTemplates::FORBIDDEN as $forbidden) {
            self::assertStringContainsString($forbidden, $docs, 'docs/themes.md описує заборонену зону');
        }
    }

    public function testFixtureThemeManifestMatchesEngineContract(): void
    {
        $manifest = Yaml::parseFile(self::root().'/tests/store/pack-demo/themes/demo/theme.yaml');
        self::assertSame(1, $manifest['format']);
        self::assertSame('demo', $manifest['key']);
        self::assertSame('engine', $manifest['from']);
        self::assertSame(StoreTemplates::CONTRACT, $manifest['contract']);
        foreach (array_keys($manifest['files']) as $rel) {
            self::assertFileExists(self::root().'/tests/store/pack-demo/themes/demo/templates/'.$rel);
            self::assertFileExists(self::root().'/templates/'.$rel, 'базовий файл рушія для заготовки');
            self::assertTrue(StoreTemplates::isAllowed($rel) && !StoreTemplates::isForbidden($rel));
        }
    }

    /** @return list<string> публічні шаблони-сторінки (з блоками, поза забороненою зоною), відносно templates/ */
    private static function pageTemplates(): array
    {
        $files = [];
        foreach (glob(self::root().'/templates/site/{,*/}*.html.twig', \GLOB_BRACE) ?: [] as $file) {
            $rel = substr(str_replace('\\', '/', $file), \strlen(self::root().'/templates/'));
            if (str_contains($rel, '/partials/') || StoreTemplates::isForbidden($rel) || 'site/base.html.twig' === $rel) {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);
        self::assertGreaterThanOrEqual(10, \count($files), implode(', ', $files));

        return $files;
    }

    /** @return list<string> */
    private static function tuple(string $python): array
    {
        preg_match_all('/"([^"]+)"/', $python, $m);

        return $m[1];
    }

    private static function withoutComments(string $twig): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root().'/'.$rel);
    }

    private static function root(): string
    {
        return str_replace('\\', '/', \dirname(__DIR__, 4));
    }
}

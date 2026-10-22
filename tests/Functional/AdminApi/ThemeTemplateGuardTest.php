<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\Theme\ThemeTemplateGuard;
use App\Site\Theme\Templates\StoreTemplates;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Страж живого шаблону (рішення власника 20.10.2026 «без Twig sandbox-обходів», рев'ю C PR #108 M1–M2 — явний allowlist
 * LiveThemePolicy): Twig рушія з усіма функціями (path, csrf_token, site_*), тож перевіряється те, що парсер бачить насправді.
 * Кожен обхід з рев'ю (app.request.server, _context, attribute(), set r = app, render(controller()), include_only) — окремий кейс.
 */
final class ThemeTemplateGuardTest extends KernelTestCase
{
    private ThemeTemplateGuard $guard;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->guard = new ThemeTemplateGuard(self::getContainer()->get(Environment::class));
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'extends @engine з блоком' => ["{% extends '@engine/site/home.html.twig' %}{% block body %}<p>hi</p>{{ parent() }}{% endblock %}"];
        yield 'extends головного namespace' => ["{% extends 'site/base.html.twig' %}"];
        yield 'include тегом і функцією' => ["{% include 'site/partials/_support.html.twig' %}{{ include('@engine/site/partials/_nav.html.twig') }}"];
        yield 'import _self і partial забороненої зони (читати можна)' => ["{% import _self as m %}{% import 'site/partials/_schema.html.twig' as schema %}{% macro a() %}x{% endmacro %}{{ m.a() }}"];
        yield 'стрілочні функції' => ["{{ [1,2]|map(v => v * 2)|join(',') }}{{ [1]|sort }}{{ [1,2]|filter((v, k) => v > 1)|length }}{{ [1]|reduce((c, v) => c + v, 0) }}"];
        yield 'constant enum-у магазину' => ["{{ constant('App\\\\Core\\\\Order\\\\Enum\\\\ShippingMethod::NovaPoshta').value }}"];
        yield 'embed' => ["{% embed '@engine/site/partials/_support.html.twig' %}{% endembed %}"];
        yield 'функції рушія' => ["<a href=\"{{ path('site_cart') }}\">{{ site_theme('logo.src') }}{{ csrf_token('cart') }}</a>"];
        yield 'app.request лише як bool' => ["{% if app.request and app.request.pathInfo matches '#^/x#' %}a{% endif %}{% if not app.request %}b{% endif %}{{ app.request ? 'c' : 'd' }}{% if app.request is null or app.request is not defined %}e{% endif %}"];
        yield 'app — дозволені ланцюжки' => ["{{ app.request.locale }}{{ app.request.pathInfo }}{{ app.request.query.get('q', '') }}{% for k, v in app.request.query.all %}{{ k }}{% endfor %}{% for m in app.flashes('ok') %}{{ m }}{% endfor %}{% if app.user %}{{ app.user.email }}{{ app.user.name }}{% endif %}{% if app.request.hasPreviousSession %}s{% endif %}"];
        yield 'властивості зі списку, масив будь-яким ключем' => ["{{ product.name }}{{ product.defaultVariant.price }}{{ block['clear'] }}{{ loop.index }}{{ totals.priceFingerprint() }}{{ item.lineTotal|money }}"];
        yield 'тести й фільтри зі списку' => ["{% if x is defined and y is not empty and z is null %}{{ x|upper|trim|striptags|slice(0, 3)|default('-') }}{{ [1,2]|first }}{{ d|date('Y') }}{% endif %}"];
        yield 'теги зі списку' => ['{% set a = 1 %}{% do a %}{% apply upper %}x{% endapply %}{% autoescape false %}y{% endautoescape %}{% with {b: 1} %}{{ b }}{% endwith %}{% verbatim %}{{ z }}{% endverbatim %}'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function denied(): iterable
    {
        yield 'синтаксис' => ['{% if %}', 'Twig:'];
        yield 'source' => ["{{ source('site/base.html.twig') }}", 'source()'];
        yield 'dump' => ['{{ dump(app) }}', 'dump()'];
        yield 'include виразом' => ['{% include app.request.query.t %}', 'не виразом'];
        yield 'include поза site' => ["{% include 'admin/layout.html.twig' %}", 'дозволені лише site/**.twig'];
        yield 'include() листа' => ["{{ include('emails/order.html.twig') }}", 'дозволені лише site/**.twig'];
        yield 'extends поза site' => ["{% extends 'base.html.twig' %}", 'extends'];
        yield 'extends виразом' => ["{% extends app.user ? 'site/a.html.twig' : 'site/b.html.twig' %}", 'не виразом'];
        yield 'map рядком' => ["{{ ['ls']|map('system') }}", '|map'];
        yield 'filter рядком' => ["{{ ['a']|filter('phpinfo') }}", '|filter'];
        yield 'sort рядком' => ["{{ ['a']|sort('shell_exec') }}", '|sort'];
        yield 'constant довільного класу' => ["{{ constant('App\\\\Kernel::FRAMEWORK_DEFAULT_TRUSTED_HOSTS') }}", 'constant()'];
        yield 'constant з об’єктом' => ["{{ constant('X', app) }}", 'constant()'];
        yield 'block з шаблоном' => ["{{ block('body', 'emails/x.html.twig') }}", 'block()'];
        yield 'use поза site' => ["{% use 'admin/blocks.html.twig' %}", 'use'];
        yield '..' => ["{% include 'site/../emails/x.html.twig' %}", 'дозволені лише site/**.twig'];
        // рев'ю C M1: секрети оточення через app.request.server і обходи
        yield 'app.request.server.get' => ["{{ app.request.server.get('DATABASE_URL') }}", 'app.request.server.get'];
        yield 'app.request.server.all' => ['{% for k, v in app.request.server.all %}{{ k }}{% endfor %}', 'app.request.server.all'];
        yield 'app.request сам' => ['{% set r = app.request %}{{ r.server.get("APP_SECRET") }}', 'app.request (рядок'];
        yield 'app.request.headers' => ["{{ app.request.headers.get('authorization') }}", 'app.request.headers'];
        yield 'app.request.cookies' => ['{{ app.request.cookies.all|json_encode }}', 'app.request.cookies'];
        yield 'app.session' => ["{{ app.session.get('x') }}", 'app.session'];
        yield 'app.token' => ['{{ app.token.user.password }}', 'app.token'];
        yield 'app.user.password' => ['{{ app.user.password }}', 'властивість користувача'];
        yield 'app.user.roles' => ['{{ app.user.roles|join }}', 'властивість користувача'];
        yield 'set r = app' => ['{% set r = app %}{{ r.request.server.get("APP_SECRET") }}', 'app (рядок'];
        yield 'app аргументом' => ["{{ include('site/partials/_support.html.twig', {a: app}) }}", 'app (рядок'];
        yield 'app у with' => ['{% with {a: app} %}{{ a.request.server.all|keys|join }}{% endwith %}', 'app (рядок'];
        yield 'app у масиві' => ['{% for r in [app] %}{{ r.request.server.all|keys|join }}{% endfor %}', 'app (рядок'];
        yield '_context' => ["{{ _context.app.request.server.get('APP_SECRET') }}", '_context'];
        yield '_context у for' => ['{% for k, v in _context %}{{ k }}{% endfor %}', '_context'];
        yield 'attribute() на app' => ["{{ attribute(app.request, 'ser' ~ 'ver').get('APP_SECRET') }}", 'attribute()'];
        yield 'attribute() на об’єкті' => ["{{ attribute(product, 'set' ~ 'Name') }}", 'attribute()'];
        yield 'метод поза списком' => ['{{ cart.clear() }}', '.clear'];
        yield 'властивість поза списком' => ['{{ product.setName("x") }}{{ user.googleSub }}', '.setName'];
        yield 'app.request як значення ?:' => ['{% set r = app.request ?: null %}{{ r.server.all|keys|join }}', 'app.request (рядок'];
        yield 'app.request через default' => ['{% set r = app.request|default(null) %}', 'app.request (рядок'];
        yield 'app.request у порівнянні' => ['{% if app.request == x %}{% endif %}', 'app.request (рядок'];
        yield 'app[dynamic]' => ["{{ app['req' ~ 'uest'].server.all|keys|join }}", 'app.'];
        yield 'macro з app' => ['{% macro m(x) %}{{ x.server.all|keys|join }}{% endmacro %}{{ _self.m(app.request) }}', 'app.request (рядок'];
        // рев'ю C M2: sub-request і читання будь-якого шаблону виразом
        yield 'render(controller())' => ["{{ render(controller('App\\\\Core\\\\AdminApi\\\\Controller\\\\MeController::me')) }}", 'render()'];
        yield 'render шляху' => ["{{ render('/api/admin/v1/me') }}", 'render()'];
        yield 'render_esi' => ["{{ render_esi(url('site_home')) }}", 'render_esi()'];
        yield 'controller' => ["{{ controller('App\\\\Kernel::boot') }}", 'controller()'];
        yield 'include_only' => ['{{ include_only(app.request.query.t) }}', 'include_only()'];
        yield 'fragment_uri' => ["{{ fragment_uri(controller('x')) }}", 'fragment_uri()'];
        yield 'template_from_string' => ["{{ include(template_from_string('{{ app }}')) }}", 'Twig:'];
        yield 'невідома функція' => ['{{ expression("1+1") }}', 'expression()'];
        yield 'фільтр invoke' => ["{{ 'phpinfo'|invoke }}", '|invoke'];
        yield 'невідомий фільтр' => ["{{ x|serialize('json') }}", '|serialize'];
        yield 'тест constant' => ["{% if x is constant('App\\\\Kernel::FRAMEWORK_DEFAULT_TRUSTED_HOSTS') %}{% endif %}", 'is constant'];
        yield 'тег sandbox' => ["{% sandbox %}{% include 'site/partials/_support.html.twig' %}{% endsandbox %}", 'Twig:'];
        yield 'тег flush' => ['{% flush %}', '{% flush %}'];
        yield 'тег deprecated' => ["{% deprecated 'x' %}", '{% deprecated %}'];
        yield '_self поза import' => ["{{ block('body', _self) }}", '_self'];
    }

    /**
     * Allowlist не відстає від рушія: кожен шаблон templates/site/**, який жива тема може перекрити (поза забороненою зоною),
     * проходить стража як є — інакше дизайнер не зміг би взяти копію шаблону рушія за основу. Нова функція чи властивість у
     * шаблоні рушія → свідомо додати до LiveThemePolicy.
     */
    public function testEveryOverridableEngineTemplatePasses(): void
    {
        /** @var string $projectDir */
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $root = $projectDir.'/templates/site';
        $checked = 0;
        $failures = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $rel = 'site/'.str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            if (StoreTemplates::isForbidden($rel)) {
                continue;
            }
            ++$checked;
            $problems = $this->guard->check($rel, (string) file_get_contents($file->getPathname()));
            if ([] !== $problems) {
                $failures[] = $rel.': '.implode('; ', $problems);
            }
        }
        self::assertGreaterThan(20, $checked);
        self::assertSame([], $failures, implode("\n", $failures));
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $template): void
    {
        self::assertSame([], $this->guard->check('site/test.html.twig', $template));
    }

    #[DataProvider('denied')]
    public function testDenied(string $template, string $reason): void
    {
        $problems = $this->guard->check('site/test.html.twig', $template);
        self::assertNotSame([], $problems, $template);
        self::assertStringContainsString($reason, implode(' | ', $problems));
    }
}

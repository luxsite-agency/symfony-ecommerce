<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostCategory;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Публічні маршрути: локалі uk (без префікса) і ru (/ru/), 404 для неопублікованих, hreflang/canonical/JSON-LD, sitemap. */
final class ContentPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
    }

    public function testPublishedPageInBothLocalesWithSeoTags(): void
    {
        $page = new Page();
        $page->setPublished(true);
        $page->setShowInHeader(true);
        $page->translate('uk')->setTitle('Доставка і оплата')->setBody('<p>Нова пошта</p>')->setMetaDescription('Умови доставки');
        $page->translate('ru')->setTitle('Доставка и оплата')->setBody('<p>Новая почта</p>');
        $page->translate('en')->setTitle('Delivery and payment')->setBody('<p>Nova Poshta</p>');
        $this->em->persist($page);
        $this->em->flush();
        // слаги робить SlugListener транслітерацією — у тестах не хардкодимо ні їх, ні набір мов
        $slug = static fn (string $locale): string => (string) $page->getTranslation($locale)?->getSlug();
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');
        /** @var string $default */
        $default = static::getContainer()->getParameter('app.default_locale');
        self::assertContains('en', $locales, 'Тестовий набір мов має містити en');
        $urlOf = fn (string $locale): string => $this->host.($locale === $default ? '' : '/'.$locale).'/'.$slug($locale).'/';

        $crawler = $this->client->request('GET', $urlOf($default));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Доставка і оплата');
        self::assertSame('uk-UA', $crawler->filter('html')->attr('lang'));
        self::assertSame($urlOf($default), $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame($urlOf($default), $crawler->filter('link[rel="alternate"][hreflang="x-default"]')->attr('href'));
        self::assertSame('Умови доставки', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringContainsString('"BreadcrumbList"', implode(' ', $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text())));
        self::assertStringContainsString('Доставка і оплата', $crawler->filter('header .nav')->text(), 'Сторінка з showInHeader — у меню шапки');
        self::assertCount(\count($locales), $crawler->filter('link[rel="alternate"][hreflang]:not([hreflang="x-default"])'), 'hreflang на кожну мову, де є переклад');
        self::assertSame($urlOf('en'), $crawler->filter('link[rel="alternate"][hreflang="en"]')->attr('href'));
        self::assertSame($urlOf('ru'), $crawler->filter('link[rel="alternate"][hreflang="ru-UA"]')->attr('href'));

        foreach (['ru' => ['Доставка и оплата', 'ru-UA'], 'en' => ['Delivery and payment', 'en']] as $locale => [$title, $lang]) {
            $crawler = $this->client->request('GET', $urlOf($locale));
            self::assertResponseIsSuccessful($locale);
            self::assertSelectorTextContains('h1', $title);
            self::assertSame($lang, $crawler->filter('html')->attr('lang'));
            self::assertSame($urlOf($locale), $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertStringContainsString($title, $crawler->filter('header .nav')->text());
        }
    }

    public function testSiteServesAnyHostButNotAdminHost(): void
    {
        $page = new Page();
        $page->setPublished(true);
        $page->translate('uk')->setTitle('Будь-який хост');
        $this->em->persist($page);
        $this->em->flush();
        $slug = (string) $page->getTranslation('uk')?->getSlug();

        // маршрути сайту без host: worktree shop-engine-<name>, prod-домен клієнта — усе працює
        $crawler = $this->client->request('GET', 'https://shop-engine-anyname.localhost/'.$slug.'/');
        self::assertResponseIsSuccessful();
        self::assertSame($this->host.'/'.$slug.'/', $crawler->filter('link[rel="canonical"]')->attr('href'), 'canonical — на канонічному site_host');

        /** @var string $adminHost */
        $adminHost = static::getContainer()->getParameter('admin_host');
        $this->client->request('GET', 'https://'.$adminHost.'/'.$slug.'/');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'На хості адмінки публічні маршрути не матчаться');
    }

    public function testUnpublishedOrUntranslatedPageIs404(): void
    {
        $draft = new Page();
        $draft->translate('uk')->setTitle('Чернетка');
        $ukOnly = new Page();
        $ukOnly->setPublished(true);
        $ukOnly->translate('uk')->setTitle('Лише українською');
        $this->em->persist($draft);
        $this->em->persist($ukOnly);
        $this->em->flush();

        $draftSlug = (string) $draft->getTranslation('uk')?->getSlug();
        $ukSlug = (string) $ukOnly->getTranslation('uk')?->getSlug();
        $this->client->request('GET', $this->host.'/'.$draftSlug.'/');
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', $this->host.'/'.$ukSlug.'/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('link[rel="alternate"]'), 'Без інших перекладів hreflang не виводиться');
        $this->client->request('GET', $this->host.'/ru/'.$ukSlug.'/');
        self::assertResponseStatusCodeSame(404, 'Нема ru-перекладу — 404 під /ru/');
        $this->client->request('GET', $this->host.'/en/'.$ukSlug.'/');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->host.'/no-such-page/');
        self::assertResponseStatusCodeSame(404);
    }

    public function testBlogListsOnlyPublishedPostsAndShowsArticle(): void
    {
        $news = new PostCategory('news');
        $news->translate('uk')->setName('Новини');
        $published = new Post();
        $published->setCategory($news);
        $published->setPublishedAt(new DateTimeImmutable('-1 day'));
        $published->setAuthorName('Редакція');
        $published->setTags('двері, поради');
        $published->translate('uk')->setTitle('Як обрати двері')->setExcerpt('Коротко про головне')->setBody('<p>Текст</p>');
        $future = new Post();
        $future->setPublishedAt(new DateTimeImmutable('+1 day'));
        $future->translate('uk')->setTitle('Майбутній запис');
        $draft = new Post();
        $draft->translate('uk')->setTitle('Чернетка запису');
        foreach ([$news, $published, $future, $draft] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/blog/');
        self::assertResponseIsSuccessful();
        $list = $crawler->filter('.cards')->text();
        self::assertStringContainsString('Як обрати двері', $list);
        self::assertStringNotContainsString('Майбутній запис', $list);
        self::assertStringNotContainsString('Чернетка запису', $list);

        $slug = (string) $published->getTranslation('uk')?->getSlug();
        $crawler = $this->client->request('GET', $this->host.'/blog/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Як обрати двері');
        self::assertStringContainsString('Редакція', $crawler->filter('.meta')->text());
        $ld = $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text());
        self::assertStringContainsString('"Article"', implode(' ', $ld));
        self::assertStringContainsString('"WebSite"', implode(' ', $ld));
        self::assertSame('article', $crawler->filter('meta[property="og:type"]')->attr('content'));

        $this->client->request('GET', $this->host.'/blog/'.(string) $future->getTranslation('uk')?->getSlug());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->host.'/ru/blog/'.$slug);
        self::assertResponseStatusCodeSame(404, 'Без ru-перекладу запис під /ru/ не існує');
        $crawler = $this->client->request('GET', $this->host.'/ru/blog/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Як обрати двері', $crawler->filter('.cards')->text(), 'У списку /ru/ запис з фолбеком на мову за замовчуванням');
        self::assertStringContainsString('/blog/'.$slug, $crawler->filter('.cards a')->first()->attr('href') ?? '', 'Посилання веде на URL мови за замовчуванням');
    }

    /** SEO п. 2: переклад лише зі слагом/метою — не переклад: 404 цією мовою, без hreflang і без URL у sitemap; із заголовком — з'являється. */
    public function testPostWithIncompleteTranslationIsNotServedInThatLocale(): void
    {
        $post = new Post();
        $post->setPublishedAt(new DateTimeImmutable('-1 day'));
        $post->translate('uk')->setTitle('Повний запис')->setBody('<p>Текст українською</p>');
        $post->translate('ru')->setSlug('polnyy-zapis')->setMetaTitle('Лише мета'); // порожня вкладка з автослагом
        $this->em->persist($post);
        $this->em->flush();
        $ukSlug = (string) $post->getTranslation('uk')?->getSlug();

        $this->client->request('GET', $this->host.'/ru/blog/polnyy-zapis');
        self::assertResponseStatusCodeSame(404, 'Без заголовка ru-версії не існує');
        $crawler = $this->client->request('GET', $this->host.'/blog/'.$ukSlug);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('link[rel="alternate"][hreflang="ru-UA"]'), 'hreflang лише для повних перекладів');
        $xml = (string) $this->client->request('GET', $this->host.'/sitemap.xml')->html();
        self::assertStringContainsString('/blog/'.$ukSlug, $xml);
        self::assertStringNotContainsString('/ru/blog/polnyy-zapis', $xml, 'Неповний переклад не в sitemap');

        $ru = $this->em->find(Post::class, $post->getId())?->getTranslation('ru');
        self::assertInstanceOf(PostTranslation::class, $ru);
        $ru->setTitle('Полный запис');
        $this->em->flush();
        $this->client->request('GET', $this->host.'/ru/blog/polnyy-zapis');
        self::assertResponseStatusCodeSame(404, 'Заголовок без тексту — ще не переклад');
        $ru->setBody('<p>&nbsp;</p>');
        $this->em->flush();
        $this->client->request('GET', $this->host.'/ru/blog/polnyy-zapis');
        self::assertResponseStatusCodeSame(404, 'Порожня розмітка — теж без тексту');
        $ru->setBody('<p>Текст по-русски</p>');
        $this->em->flush();
        $this->client->request('GET', $this->host.'/ru/blog/polnyy-zapis');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', $this->host.'/blog/'.$ukSlug);
        self::assertSame($this->host.'/ru/blog/polnyy-zapis', $crawler->filter('link[rel="alternate"][hreflang="ru-UA"]')->attr('href'));
        self::assertStringContainsString('/ru/blog/polnyy-zapis', (string) $this->client->request('GET', $this->host.'/sitemap.xml')->html());
    }

    /** Неповний переклад (заголовок без тексту) у списку /en/blog і на головній /en/ — картка мовою за замовчуванням, а не порожня. */
    public function testIncompleteTranslationFallsBackToDefaultLocaleInCards(): void
    {
        $post = new Post();
        $post->setPublishedAt(new DateTimeImmutable('-1 hour'));
        $post->translate('uk')->setTitle('Запис з фолбеком')->setBody('<p>Текст</p>');
        $post->translate('en')->setTitle('Half translated'); // заголовок без тексту
        $this->em->persist($post);
        $this->em->flush();
        $ukSlug = (string) $post->getTranslation('uk')?->getSlug();

        foreach (['/en/blog/', '/en/'] as $path) {
            $crawler = $this->client->request('GET', $this->host.$path);
            self::assertResponseIsSuccessful($path);
            $card = $crawler->filter('.cards a[href$="/blog/'.$ukSlug.'"]');
            self::assertGreaterThan(0, $card->count(), $path.': посилання на uk-запис');
            $text = $crawler->filter('.cards')->text();
            self::assertStringContainsString('Запис з фолбеком', $text, $path.': заголовок мовою за замовчуванням');
            self::assertStringNotContainsString('Half translated', $text, $path);
        }
    }

    public function testHomeAndSitemap(): void
    {
        $page = new Page();
        $page->setPublished(true);
        $page->setShowInFooter(true);
        $page->translate('uk')->setTitle('Про нас');
        $page->translate('ru')->setTitle('О нас');
        $post = new Post();
        $post->setPublishedAt(new DateTimeImmutable('-2 hours'));
        $post->translate('uk')->setTitle('Перший запис')->setBody('<p>Текст</p>');
        $draft = new Page();
        $draft->translate('uk')->setTitle('Прихована');
        foreach ([$page, $post, $draft] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Про нас', $crawler->filter('main')->text());
        self::assertStringContainsString('Перший запис', $crawler->filter('main')->text());
        $this->client->request('GET', $this->host.'/ru/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->host.'/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = (string) $this->client->getResponse()->getContent();
        $uk = (string) $page->getTranslation('uk')?->getSlug();
        $ru = (string) $page->getTranslation('ru')?->getSlug();
        $postSlug = (string) $post->getTranslation('uk')?->getSlug();
        self::assertStringContainsString('<loc>'.$this->host.'/'.$uk.'/</loc>', $xml);
        self::assertStringContainsString('<loc>'.$this->host.'/ru/'.$ru.'/</loc>', $xml);
        self::assertStringContainsString('hreflang="ru-UA" href="'.$this->host.'/ru/'.$ru.'/"', $xml);
        self::assertStringContainsString('<loc>'.$this->host.'/en/</loc>', $xml, 'Головна кожною мовою з параметра');
        self::assertStringContainsString('<loc>'.$this->host.'/blog/'.$postSlug.'</loc>', $xml);
        self::assertStringNotContainsString('/ru/blog/'.$postSlug, $xml, 'Запис без ru-перекладу не має ru-URL');
        self::assertStringNotContainsString((string) $draft->getTranslation('uk')?->getSlug(), $xml);
    }

    /** Каталог (Ф1) замінив заглушки: тут лише інтеграція з каркасом — меню, мови, 404; лістинг і картку перевіряють CategoryPageTest/ProductPageTest. */
    public function testCatalogPagesRespectActivityAndLocale(): void
    {
        $category = new Category();
        $category->translate('uk')->setName('Двері')->setSlug('dveri');
        $product = new Product('ML-14', 'UAH');
        $product->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14');
        $product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $product->addCategory($category);
        $hidden = new Product('HID', 'UAH');
        $hidden->setActive(false);
        $hidden->translate('uk')->setName('Прихований')->setSlug('prykhovanyi');
        foreach ([$category, $product, $hidden] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/dveri/');
        self::assertResponseIsSuccessful();
        self::assertSame('Двері', $crawler->filter('h1')->text());
        self::assertStringContainsString('Двері', $crawler->filter('header .nav')->text(), 'Коренева категорія — у меню');

        $crawler = $this->client->request('GET', $this->host.'/dveri-ml-14');
        self::assertResponseIsSuccessful();
        self::assertSame('Двері ML 14', $crawler->filter('h1')->text());

        $this->client->request('GET', $this->host.'/prykhovanyi');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->host.'/ru/dveri-ml-14');
        self::assertResponseStatusCodeSame(404);
    }
}

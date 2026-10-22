<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\Ai\AiFeature;
use App\Core\Ai\AiUsageStatus;
use App\Core\Ai\Entity\AiUsage;
use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostCategory;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\NotFoundLog;
use App\Core\Seo\RedirectManager;
use App\Core\Shared\Money\Money;
use BackedEnum;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Повнота перекладу адмінки (uk/en/ru): фікстура з латинськими даними для кожного CRUD, обхід усіх GET-сторінок адмінки
 * від дашборда (меню, «Додати», дії рядка, власні сторінки) і перевірки на кожній сторінці:
 *  - відповідь 2xx, <html lang> = мова адмінки;
 *  - колектор перекладів профайлера: жодного ключа без перекладу (missing) чи взятого з мови-фолбеку (domain:id);
 *  - en: жодної кирилиці у видимому тексті (текст body, placeholder/title/aria-label/alt, підписи кнопок, <option>, <title>);
 *  - uk/ru: жодного «Null» і жодного сирого коду enum (значення й імена case) окремим словом.
 * Повідомлення про падіння — список задач для розробника: сторінка → що саме не перекладено. Повторюваний текст (меню,
 * шапка) і ключі показуються лише на першій сторінці, де трапились.
 */
final class AdminTranslationCoverageTest extends AdminWebTestCase
{
    private const int MAX_PAGES = 120;
    private const int MAX_SNIPPETS = 10;

    /** Домени, ключі яких мають бути перекладені мовою адмінки. */
    private const array CHECKED_DOMAINS = ['admin', 'validators', 'EasyAdminBundle', 'messages', 'security'];

    /** Enum-и, коди яких не мають потрапляти в інтерфейс як є (відсутні класи пропускаються). */
    private const array ENUMS = [
        'App\Core\Order\Enum\OrderStatus',
        'App\Core\Order\Enum\PaymentStatus',
        'App\Core\Order\Enum\PaymentMethod',
        'App\Core\Order\Enum\ShippingMethod',
        'App\Core\Order\Enum\OrderSource',
        'App\Core\Catalog\Enum\ReviewStatus',
        'App\Core\B2b\Enum\ApplicationStatus',
        'App\Core\Pricing\Promotion\Enum\PromotionType',
        'App\Core\Seo\Enum\RedirectKind',
        'App\Core\Security\Enum\UserRole',
        'App\Core\Changeset\ChangesetStatus',
        'App\Core\Audit\AuditAction',
        'App\Core\Audit\AuditSource',
        'App\Core\Ai\AiFeature',
        'App\Core\Ai\AiUsageStatus',
    ];

    /** Шляхи, яких обхід не відкриває: вихід/вхід, профайлер, REST/MCP, файли (прайс, експорт). */
    private const string SKIP_PATH = '#^/(logout|login|_profiler|_wdt|api/admin|mcp|price-list|media)(/|$)#';

    /** Дії, що змінюють дані або віддають не сторінку: видалення, пакетні дії, фільтри/автодоповнення (AJAX), експорт. */
    private const string SKIP_ACTION = '#(/delete$|/batch-|render-filters|autocomplete|/export$)#';

    /** Символ, що «приклеює» слово до технічного рядка (ключ settings.x, шлях /a/b, e-mail, slug-a-b): тоді це не сирий код у тексті. */
    private const string WORD_GLUE = '[\p{L}\p{N}_.\-\/:@]';

    /** @var array<string, list<string>> ключ сторінки (шлях з {id}) → проблеми */
    private array $problems = [];
    /** @var array<string, string> ключ сторінки → перший URL */
    private array $firstUrl = [];
    /** @var array<string, true> уже показані фрагменти тексту й ключі (щоб меню не повторювалось на кожній сторінці) */
    private array $reported = [];
    private int $dashboardMessages = -1;
    private ?string $adminHost = null;

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'uk' => ['uk'];
        yield 'ru' => ['ru'];
    }

    #[DataProvider('locales')]
    public function testEveryAdminPageIsTranslated(string $locale): void
    {
        $admin = $this->createUser('tester@example.test', UserRole::Admin);
        $this->seed($admin);

        // сторінка входу без сесії — мова з ?_locale (AdminLocaleListener зберігає її до POST входу)
        $this->visit('/login?_locale='.$locale, $locale, 'login');

        $this->client->loginUser($admin, 'admin');
        $pages = $this->crawl($locale);

        self::assertGreaterThan(0, $this->dashboardMessages, 'колектор перекладів профайлера нічого не зібрав на дашборді — перевірка ключів не працює');
        self::assertGreaterThanOrEqual(20, $pages, 'обхід знайшов підозріло мало сторінок — меню не розпарсилось?');

        if ([] !== $this->problems) {
            $lines = [];
            foreach ($this->problems as $key => $problems) {
                $lines[] = \sprintf('%s  (GET %s)', $key, $this->firstUrl[$key] ?? $key);
                foreach ($problems as $problem) {
                    $lines[] = '    - '.$problem;
                }
            }
            self::fail(\sprintf("Адмінка [%s]: %d сторінок з проблемами перекладу (обійдено %d):\n%s", $locale, \count($this->problems), $pages, implode("\n", $lines)));
        }
    }

    /** Обхід у ширину від дашборда. Повертає кількість відкритих сторінок. */
    private function crawl(string $locale): int
    {
        $queue = ['/?_locale='.$locale];
        $seen = ['/' => true];
        $visited = 0;
        while ([] !== $queue && $visited < self::MAX_PAGES) {
            $url = array_shift($queue);
            ++$visited;
            foreach ($this->visit($url, $locale) as $link) {
                $key = self::pageKey($link);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = $link;
                }
            }
        }

        return $visited;
    }

    /**
     * Відкриває сторінку з профайлером, перевіряє її і повертає посилання на інші сторінки адмінки (шлях+query).
     *
     * @return list<string>
     */
    private function visit(string $url, string $locale, ?string $key = null): array
    {
        $key ??= self::pageKey($url);
        $this->firstUrl[$key] ??= $url;
        $this->client->enableProfiler();
        $this->client->request('GET', $this->adminUrl($url));
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();

        if ($response->isRedirection()) {
            $target = $this->localPath((string) $response->headers->get('Location'), $url);
            if (null === $target || str_starts_with($target, '/login')) {
                $this->problem($key, \sprintf('HTTP %d → %s (очікувалась сторінка)', $status, (string) $response->headers->get('Location')));

                return [];
            }

            return [$target];
        }

        $html = (string) $response->getContent();
        if ($status < 200 || $status >= 300) {
            preg_match('#<title>(.*?)</title>#s', $html, $m);
            $this->problem($key, \sprintf('HTTP %d: %s', $status, mb_substr(trim(html_entity_decode(strip_tags($m[1] ?? ''))), 0, 200)));

            return [];
        }
        if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return []; // завантаження файлу
        }

        $this->checkTranslatorMessages($key, $locale);

        $dom = self::dom($html);
        $xpath = new DOMXPath($dom);
        $lang = $xpath->query('/html')->item(0);
        $htmlLang = $lang instanceof DOMElement ? $lang->getAttribute('lang') : '';
        if ($htmlLang !== $locale) {
            $this->problem($key, \sprintf('<html lang="%s">, очікувалось "%s"', $htmlLang, $locale));
        }

        $chunks = self::visibleText($xpath, false);
        if ('en' === $locale) {
            $this->checkCyrillic($key, $chunks);
        } else {
            $this->checkWords($key, $chunks, '/(?<!'.self::WORD_GLUE.')(Null)(?!'.self::WORD_GLUE.')/u', '«Null» замість порожнього значення');
            $this->checkWords($key, self::visibleText($xpath, true), self::enumPattern(), 'сирий код enum');
        }

        return 'login' === $key ? [] : $this->links($xpath, $url);
    }

    private function checkTranslatorMessages(string $key, string $locale): void
    {
        $profile = $this->client->getProfile();
        if (!$profile instanceof Profile || !$profile->hasCollector('translation')) {
            $this->problem($key, 'профайлер не зібрав колектор translation');

            return;
        }
        /** @var \Symfony\Component\Translation\DataCollector\TranslationDataCollector $collector */
        $collector = $profile->getCollector('translation');
        $messages = $collector->getMessages();
        $messages = $messages instanceof Data ? $messages->getValue(true) : $messages;
        if ('/' === $key) {
            $this->dashboardMessages = \count($messages);
        }
        foreach ($messages as $message) {
            $domain = (string) ($message['domain'] ?? 'messages');
            $id = (string) ($message['id'] ?? '');
            $state = $message['state'] ?? null;
            // рядки без літер (—, 0.00, 10 у placeholder) перекладати нічого
            if ('' === $id || !preg_match('/\pL/u', $id) || !\in_array($domain, self::CHECKED_DOMAINS, true) || DataCollectorTranslator::MESSAGE_DEFINED === $state) {
                continue;
            }
            $what = DataCollectorTranslator::MESSAGE_MISSING === $state
                ? 'немає перекладу'
                : \sprintf('лише у фолбеку %s', (string) ($message['fallbackLocale'] ?? '?'));
            $label = \sprintf('%s:%s', $domain, $id);
            if (!$this->reportOnce('key:'.$locale.':'.$label)) {
                continue;
            }
            $this->problem($key, \sprintf('ключ %s — %s (%s)', $label, $what, (string) ($message['locale'] ?? $locale)));
        }
    }

    /** @param array<string, string> $chunks джерело → текст */
    private function checkCyrillic(string $key, array $chunks): void
    {
        $found = 0;
        foreach ($chunks as $source => $text) {
            preg_match_all('/.{0,30}[\x{0400}-\x{04FF}]+(?:[^\p{L}]{0,3}[\x{0400}-\x{04FF}]+)*.{0,30}/u', $text, $m);
            foreach ($m[0] as $snippet) {
                if (!$this->reportOnce('cyr:'.$source.':'.$snippet)) {
                    continue;
                }
                if (++$found > self::MAX_SNIPPETS) {
                    $this->problem($key, '… ще кирилиця (показано перші '.self::MAX_SNIPPETS.')');

                    return;
                }
                $this->problem($key, \sprintf('кирилиця [%s]: «%s»', $source, trim($snippet)));
            }
        }
    }

    /** @param array<string, string> $chunks */
    private function checkWords(string $key, array $chunks, string $pattern, string $what): void
    {
        $found = 0;
        foreach ($chunks as $source => $text) {
            preg_match_all($pattern, $text, $m, \PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$word, $offset]) {
                $context = trim(mb_strcut($text, max(0, $offset - 40), \strlen($word) + 80));
                if (!$this->reportOnce('word:'.$source.':'.$word.':'.$context)) {
                    continue;
                }
                if (++$found > self::MAX_SNIPPETS) {
                    $this->problem($key, '… ще ('.$what.')');

                    return;
                }
                $this->problem($key, \sprintf('%s «%s» [%s]: «%s»', $what, $word, $source, $context));
            }
        }
    }

    /**
     * Видимий текст сторінки за джерелами: текст body (без script/style), атрибути placeholder/title/aria-label/alt,
     * підписи кнопок-input, <title>. Для пошуку сирих кодів ($prose) — без code/pre/kbd/samp і без значень полів (textarea).
     *
     * @return array<string, string>
     */
    private static function visibleText(DOMXPath $xpath, bool $prose): array
    {
        // перемикач мови показує мови їхніми самоназвами (українська, русский) — це не текст інтерфейсу
        $exclude = 'ancestor::script or ancestor::style or ancestor::a[contains(@href, "_locale=")]'
            // юридичний текст ліцензії є лише uk/en (ru-адмінка показує en) — сирі коди в ньому не шукаємо
            // translate="no" — назви чужого інтерфейсу (пункти меню Google Cloud Console у «Вході через Google»), не наш текст
            .($prose ? ' or ancestor::code or ancestor::pre or ancestor::kbd or ancestor::samp or ancestor::textarea or ancestor::*[@id="license-text"] or ancestor::*[@translate="no"]' : '');
        $parts = [];
        foreach ($xpath->query('//body//text()[not('.$exclude.')]') as $node) {
            $parts[] = $node->nodeValue;
        }
        $chunks = ['body' => self::squash(implode(' ', $parts))];
        foreach (['placeholder', 'title', 'aria-label', 'alt'] as $attribute) {
            $values = [];
            foreach ($xpath->query('//body//*[@'.$attribute.']') as $node) {
                \assert($node instanceof DOMElement);
                $values[] = $node->getAttribute($attribute);
            }
            $chunks['@'.$attribute] = self::squash(implode(' | ', $values));
        }
        $values = [];
        foreach ($xpath->query('//input[@type="submit" or @type="button" or @type="reset"][@value]') as $node) {
            \assert($node instanceof DOMElement);
            $values[] = $node->getAttribute('value');
        }
        $chunks['button value'] = self::squash(implode(' | ', $values));
        $title = $xpath->query('//head/title')->item(0);
        $chunks['<title>'] = self::squash($title instanceof DOMNode ? (string) $title->nodeValue : '');

        return array_filter($chunks, static fn (string $text): bool => '' !== $text);
    }

    /** @return list<string> посилання на сторінки адмінки (шлях+query), без пропущених */
    private function links(DOMXPath $xpath, string $current): array
    {
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            \assert($a instanceof DOMElement);
            if (str_contains(' '.$a->getAttribute('class').' ', ' action-delete ')) {
                continue;
            }
            $path = $this->localPath($a->getAttribute('href'), $current);
            if (null !== $path) {
                $links[] = $path;
            }
        }

        return array_values(array_unique($links));
    }

    /** Абсолютний/відносний href → шлях+query на хості адмінки або null (інший хост, якір, пропущені маршрути). */
    private function localPath(string $href, string $current): ?string
    {
        $href = trim($href);
        if ('' === $href || str_starts_with($href, '#') || preg_match('#^(javascript|mailto|tel|data):#i', $href)) {
            return null;
        }
        if (str_starts_with($href, '?')) {
            $href = (string) parse_url($current, \PHP_URL_PATH).$href;
        }
        $parts = parse_url($href);
        if (false === $parts) {
            return null;
        }
        $this->adminHost ??= (string) static::getContainer()->getParameter('admin_host');
        if (isset($parts['host']) && 0 !== strcasecmp($parts['host'], $this->adminHost)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (!str_starts_with($path, '/')) {
            return null;
        }
        $query = $parts['query'] ?? '';
        if (preg_match(self::SKIP_PATH, $path) || preg_match(self::SKIP_ACTION, $path) || preg_match('/(^|&)_locale=/', $query)) {
            return null;
        }

        return $path.('' !== $query ? '?'.$query : '');
    }

    /** Ключ сторінки для дедуплікації: шлях без query, числа → {id} (варіанти сортування, сторінок, фільтрів — одна сторінка). */
    private static function pageKey(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return preg_replace('#/\d+(?=/|$)#', '/{id}', '' === $path ? '/' : $path) ?? $path;
    }

    private static function enumPattern(): string
    {
        static $pattern = null;
        if (null !== $pattern) {
            return $pattern;
        }
        $terms = [];
        foreach (self::ENUMS as $class) {
            if (!enum_exists($class)) {
                continue;
            }
            foreach ($class::cases() as $case) {
                // усі перевіряні enum — рядкові (BackedEnum<string>)
                $terms[] = $case->name;
                $terms[] = $case->value;
            }
        }
        // Manager — і роль, і частина назв продуктів (Google Tag Manager, Meta Commerce Manager)
        $terms = array_unique(array_filter($terms, static fn (string $t): bool => \strlen($t) > 2 && 'Manager' !== $t));
        usort($terms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $pattern = '/(?<!'.self::WORD_GLUE.')('.implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms)).')(?!'.self::WORD_GLUE.')/u';
    }

    private static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        // UTF-8 без залежності від <meta charset>: усе не-ASCII — числовими сутностями
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function reportOnce(string $token): bool
    {
        if (isset($this->reported[$token])) {
            return false;
        }
        $this->reported[$token] = true;

        return true;
    }

    private function problem(string $key, string $problem): void
    {
        $this->problems[$key][] = $problem;
    }

    /**
     * Рядок у кожному CRUD адмінки, тексти латиницею в усіх мовах контенту (uk, ru, en) і без слів, що збігаються з кодами enum.
     * Медіа — запис без файлу (як у ProductReviewCrudTest); налаштування — з міграцій.
     */
    private function seed(User $admin): void
    {
        $em = $this->em();
        $s = bin2hex(random_bytes(3));
        $manager = $this->createUser('orin-'.$s.'@example.test', UserRole::Manager);

        $group = new CustomerGroup('trade_tier_'.$s, 'Trade tier');
        $brand = new Brand('Velora', 'velora-'.$s);
        $root = new Category();
        $child = new Category($root);
        $attribute = new Attribute('finish_tone_'.$s, AttributeType::Select);
        $oak = $attribute->addValue('oak');
        $product = new Product('VEL-'.$s, 'UAH');
        foreach (['uk', 'ru', 'en'] as $l) {
            $root->translate($l)->setName('Doorways')->setSlug('doorways-'.$l.'-'.$s);
            $child->translate($l)->setName('Frames')->setSlug('frames-'.$l.'-'.$s);
            $attribute->translate($l)->setName('Finish tone');
            $oak->translate($l)->setValue('Oak veneer');
            $product->translate($l)->setName('Velora door frame')->setSlug('velora-frame-'.$l.'-'.$s)
                ->setShortDescription('Solid oak frame')->setDescription('<p>Solid oak frame with brass hinges.</p>')
                ->setMetaTitle('Velora door frame')->setMetaDescription('Solid oak frame with brass hinges');
        }
        $product->setBrand($brand);
        $product->addCategory($child);
        $product->setMainCategory($child);
        $product->addAttributeOption($oak);
        $variant = $product->getDefaultVariant();
        $variant->setPrice(Money::of(100_000, 'UAH'), Money::of(120_000, 'UAH'));
        $variant->setStock('12');
        $variant->addPriceTier('5', Money::of(90_000, 'UAH'), $group);
        $trim = $product->addOptionGroup('trim_kit');
        $brass = $trim->addAbsoluteModifier('brass', Money::of(5_000, 'UAH'));
        foreach (['uk', 'ru', 'en'] as $l) {
            $trim->translate($l)->setName('Trim kit');
            $brass->translate($l)->setLabel('Brass trim');
        }
        $media = new Media('velora-'.$s.'.jpg', 'image/jpeg', 1000);
        $media->setAlt('Velora frame sample');
        $product->addMedia($media);

        $customer = new Customer('mira-'.$s.'@example.test', 'Mira Kovalenko', 'uk');
        $customer->setPhone('0501112233');
        $review = new ProductReview($product, $customer, 4, 'Solid frame, smooth hinges, fair price.', 'uk');
        $application = new WholesaleApplication($customer, 'Velora Trading', (string) random_int(10_000_000, 99_999_999), false, 'Mira Kovalenko', '0501112233', 'Trade terms for a joinery shop');
        $company = new Company('Velora Trading LLC', (string) random_int(10_000_000, 99_999_999), true, 'Mira Kovalenko', '0501112233');

        $promotion = new Promotion('Spring markdown', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addBrand($brand);
        $promotion->addCustomerGroup($group);

        $page = new Page();
        $page->setPublished(true);
        $postCategory = new PostCategory('journal_'.$s);
        $post = new Post();
        $post->setCategory($postCategory);
        $post->setPublishedAt(new DateTimeImmutable('-1 day'));
        $post->setAuthorName('Lars Hedin');
        foreach (['uk', 'ru', 'en'] as $l) {
            $page->translate($l)->setTitle('Delivery terms')->setSlug('delivery-terms-'.$l.'-'.$s)->setBody('<p>We ship across the country.</p>');
            $postCategory->translate($l)->setName('Journal')->setSlug('journal-'.$l.'-'.$s);
            $post->translate($l)->setTitle('Choosing a door frame')->setSlug('choosing-frame-'.$l.'-'.$s)
                ->setExcerpt('Oak or ash: a short guide.')->setBody('<p>Oak or ash: a short guide.</p>');
        }

        foreach ([$group, $brand, $root, $child, $attribute, $product, $media, $customer, $review, $application, $company, $promotion, $page, $postCategory, $post] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $container = static::getContainer();
        $container->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Mira Kovalenko', '0501112233', [['variant' => $variant, 'quantity' => '2']], 'mira-'.$s.'@example.test', 'Call before delivery'));

        $container->get(RedirectManager::class)->save('/old-velora-'.$s, RedirectCode::Gone, null);
        $container->get(NotFoundLog::class)->record('/missing-velora-'.$s, 'https://example.test/blog');

        // журнал змін: правка з адмінки від імені адміністратора
        $container->get(AdminAuditContext::class)->runAs(new AuditActor(AuditSource::AdminUi, $admin->getId(), $admin->getDisplayName()), function () use ($variant, $promotion): void {
            $variant->setStock('7');
            $promotion->setPriority(2);
            $this->em()->flush();
        });

        // пакет змін (E15): ціна варіанта від менеджера через AI API
        $container->get(ChangesetPlanner::class)->propose(
            ['title' => 'Velora price review', 'operations' => [['op' => 'set_variant_price', 'variant' => 'VEL-'.$s, 'price' => '1050.00', 'old_price' => null, 'expect' => ['price' => '1000.00', 'old_price' => '1200.00']]]],
            ChangesetAuthor::of($manager, AuditSource::AiApi, 7),
        );

        // планувальник пакета чистить EntityManager — користувачів беремо заново
        $admin = $this->em()->find(User::class, $admin->getId()) ?? $admin;
        $manager = $this->em()->find(User::class, $manager->getId()) ?? $manager;
        $container->get(AdminApiTokens::class)->issue($admin, 'Build bot', [ApiScope::CatalogRead], 30, $admin);

        $ok = new AiUsage(AiFeature::Describe, $admin->getId(), $admin->getDisplayName(), 'product', $product->getId());
        $ok->ok('openai/gpt-4o-mini', 420, 180, 0.0012, 1800);
        $failed = new AiUsage(AiFeature::Translate, $manager->getId(), $manager->getDisplayName(), 'product', $product->getId());
        $failed->failed(AiUsageStatus::Error, 'Upstream timeout after 30 s', 'openai/gpt-4o-mini', 30_000);
        $em = $this->em();
        $em->persist($ok);
        $em->persist($failed);
        $em->flush();
        $em->clear();
    }
}

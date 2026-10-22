<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Shared\Http\ResponseMarker;
use App\Core\Shared\Runtime\BuildInfo;
use App\Site\Feed\MerchantFeedGenerator;
use App\Site\Feed\MerchantFeedPaths;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Підпис рушія (власник 19.10.2026): кожна HTML-сторінка сайту й адмінки, sitemap, фід Merchant і лист несуть повну назву
 * рушія незалежно від теми; тема, що перекрила base.html.twig без meta generator, підпис не губить.
 */
final class PlatformSignatureTest extends CatalogPageTestCase
{
    private const string NAME = 'Symfony ecommerce LuxSite';

    public function testNameIsTheEngineName(): void
    {
        self::assertSame(self::NAME, BuildInfo::NAME);
        self::assertSame(self::NAME.' · https://luxsite.agency', BuildInfo::LINE);
    }

    public function testEveryPageTypeIsSigned(): void
    {
        $locale = self::defaultLocale();
        /** @var string $adminHost */
        $adminHost = static::getContainer()->getParameter('admin_host');
        $pages = [
            'головна' => [$this->url('site_home', ['_locale' => $locale]), 200],
            'категорія' => [$this->categoryUrl($this->fx->shoes, $locale), 200],
            'товар' => [$this->productUrl($this->fx->p[array_key_first($this->fx->p)], $locale), 200],
            'кошик' => [$this->url('site_cart', ['_locale' => $locale]), 200],
            'кабінет (вхід)' => [$this->url('site_account_login', ['_locale' => $locale]), 200],
            'адмінка (вхід)' => ['https://'.$adminHost.'/login', 200],
        ];
        foreach ($pages as $label => [$url, $status]) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame($status, $label);
            self::assertSignedHtml($this->client->getResponse(), $label);
        }
    }

    public function testErrorPageIsSigned(): void
    {
        // сторінка 404 сайту (templates/bundles/TwigBundle/Exception), як у проді — без debug-сторінки винятку
        self::ensureKernelShutdown();
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/no-such-page-'.$this->suffix);
        self::assertResponseStatusCodeSame(404);
        self::assertSignedHtml($client->getResponse(), '404');
    }

    public function testSitemapFeedAndMailAreSigned(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::NAME, $response->headers->get('X-Powered-By'));
        self::assertMatchesRegularExpression('#^<\?xml[^>]*\?>\n<!-- '.preg_quote(BuildInfo::LINE, '#').' -->\n<urlset#', (string) $response->getContent());

        $locale = self::defaultLocale();
        static::getContainer()->get(MerchantFeedGenerator::class)->generate($locale);
        $feed = (string) file_get_contents(static::getContainer()->get(MerchantFeedPaths::class)->file($locale));
        self::assertStringContainsString('<!-- '.BuildInfo::LINE.' -->', $feed);
        self::assertStringContainsString('<generator>'.self::NAME.'</generator>', $feed);
        self::assertNotFalse(simplexml_load_string($feed), 'фід лишається валідним XML');

        static::getContainer()->get(MailerInterface::class)->send((new Email())->from('shop@example.test')->to('a@example.test')->subject('x')->text('x'));
        $message = self::getMailerMessage();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame(self::NAME, $message->getHeaders()->get('X-Mailer')?->getBodyAsString());
    }

    public function testThemeWithoutMetaOrDoctypeStillSigned(): void
    {
        // тема перекрила base.html.twig: без doctype, без meta generator, з іншим <head>
        $response = new Response('<html lang="uk"><head><title>t</title></head><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $kernel = static::getContainer()->get('kernel');
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(
            new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );
        self::assertSignedHtml($response, 'тема без meta', false);
        self::assertStringStartsWith('<!-- '.BuildInfo::LINE." -->\n<html", (string) $response->getContent());

        // повторний прохід нічого не дублює; фрагмент без <html> не чіпається; 3xx і 5xx — лише заголовок
        $html = (string) $response->getContent();
        self::assertSame($html, ResponseMarker::html($html));
        self::assertSame('<div>x</div>', ResponseMarker::html('<div>x</div>'));
        // тема, що вивела свій generator (інша назва), отримує й наш
        $own = ResponseMarker::html('<!DOCTYPE html><html><head><meta name="generator" content="Theme X"></head></html>');
        self::assertStringContainsString('<meta name="generator" content="'.self::NAME.'">', $own);
        self::assertStringContainsString('<meta name="generator" content="Theme X">', $own);
    }

    private static function assertSignedHtml(Response $response, string $label, bool $doctype = true): void
    {
        $html = (string) $response->getContent();
        self::assertSame(self::NAME, $response->headers->get('X-Powered-By'), $label.': X-Powered-By');
        if ($doctype) {
            self::assertMatchesRegularExpression('#^\s*<!DOCTYPE html>\n<!-- '.preg_quote(BuildInfo::LINE, '#').' -->\n#i', $html, $label.': коментар одразу після doctype');
        }
        self::assertMatchesRegularExpression('#<html\b[^>]* data-engine="'.self::NAME.'"#', $html, $label.': data-атрибут на <html>');
        self::assertSame(1, substr_count($html, '<meta name="generator" content="'.self::NAME.'">'), $label.': один meta generator');
        self::assertMatchesRegularExpression('#<head\b[^>]*>(?:\s*<meta charset=[^>]*>)?\s*<meta name="generator"#i', $html, $label.': generator на початку <head>');
        self::assertSame(1, substr_count($html, BuildInfo::LINE), $label.': коментар один');
    }
}

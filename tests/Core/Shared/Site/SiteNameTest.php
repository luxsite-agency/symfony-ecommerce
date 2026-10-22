<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared\Site;

use App\Core\Shared\Site\SiteName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** app.site_name: рядок для всіх мов або мапа за локаллю з фолбеком; у Twig — мовою запиту, у JSON-LD — рядком. */
final class SiteNameTest extends TestCase
{
    public function testPlainStringIsTheSameForEveryLocale(): void
    {
        $name = new SiteName('Shop Engine', 'uk', new RequestStack());

        self::assertSame('Shop Engine', $name->forLocale('en'));
        self::assertSame('Shop Engine', (string) $name);
        self::assertSame('"Shop Engine"', json_encode($name));
    }

    public function testMapFollowsRequestLocaleWithDefaultFallback(): void
    {
        $requests = new RequestStack();
        $name = new SiteName(['uk' => 'Мерч Сокіл 77', 'en' => 'Sokil Workshop Store'], 'uk', $requests);

        self::assertSame('Мерч Сокіл 77', (string) $name, 'без запиту — мова за замовчуванням');
        $request = Request::create('/en/');
        $request->setLocale('en');
        $requests->push($request);
        self::assertSame('Sokil Workshop Store', (string) $name);
        self::assertSame('"Sokil Workshop Store"', json_encode($name, \JSON_UNESCAPED_UNICODE));
        self::assertSame('Мерч Сокіл 77', $name->forLocale('ru'), 'нема перекладу — мова за замовчуванням');
        self::assertSame('Мерч Сокіл 77', $name->forDefaultLocale());
        self::assertSame('Only EN', (new SiteName(['en' => 'Only EN'], 'uk', new RequestStack()))->forLocale('uk'), 'нема й дефолту — перша непорожня');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Site\Feed\FeedChannel;
use App\Site\Feed\MerchantFeedGenerator;
use App\Site\Feed\MerchantFeedPaths;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Маршрути віддають згенеровані файли фідів (Google Merchant, Meta); фіду ще нема або мови нема в app.locales — 404 (генерація лише командою). */
final class MerchantFeedTest extends WebTestCase
{
    public function testServesGeneratedFileAnd404Otherwise(): void
    {
        $client = static::createClient();
        $paths = static::getContainer()->get(MerchantFeedPaths::class);
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');
        [$first, $second] = $locales;

        static::getContainer()->get(MerchantFeedGenerator::class)->generate($first);
        @unlink($paths->file($second));
        @unlink($paths->file($second, FeedChannel::Meta));

        $client->request('GET', '/feeds/google-merchant-'.$first.'.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringContainsString('xmlns:g="'.MerchantFeedGenerator::NS.'"', (string) file_get_contents($paths->file($first)));

        $client->request('GET', '/feeds/meta-'.$first.'.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringContainsString('xmlns:g="'.MerchantFeedGenerator::NS.'"', (string) file_get_contents($paths->file($first, FeedChannel::Meta)));
        $client->request('GET', '/feeds/meta-'.$second.'.xml');
        self::assertResponseStatusCodeSame(404, 'Meta: ще не згенеровано');

        $client->request('GET', '/feeds/google-merchant-'.$second.'.xml');
        self::assertResponseStatusCodeSame(404, 'ще не згенеровано');
        $client->request('GET', '/feeds/google-merchant-xx.xml');
        self::assertResponseStatusCodeSame(404);
    }
}

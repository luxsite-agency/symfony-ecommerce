<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Kernel;
use App\Site\Twig\GmbReviewsFallbackExtension;
use Luxsite\GmbReviews\Client;
use Luxsite\GmbReviewsBundle\GmbReviewsBundle;

/**
 * Віджет gbp-manager — необов'язковий пакет (docs/addons.md): рушій ставиться і працює без luxsite/gmb-reviews-*
 * (CI — без пакета), бандл реєструється лише встановленим, без нього gmb_* — заглушки, блоків оцінки нема навіть із токеном.
 * Тест проходить в обох станах; з пакетом поведінку блоків перевіряє GbpRatingTest.
 */
final class GmbReviewsOptionalTest extends CatalogPageTestCase
{
    public function testPackagesAreSuggestedNotRequired(): void
    {
        $root = \dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('luxsite/gmb-reviews-bundle', $composer['require']);
        self::assertArrayNotHasKey('luxsite/gmb-reviews-core', $composer['require']);
        self::assertArrayHasKey('luxsite/gmb-reviews-bundle', $composer['suggest']);
        self::assertStringNotContainsString('luxsite/gmb-reviews', (string) file_get_contents($root.'/composer.lock'), 'у lock рушія (і в переліку компонентів /license) пакетів нема');
        self::assertStringNotContainsString('GmbReviews', (string) file_get_contents($root.'/config/bundles.php'), 'бандл реєструє Kernel, не bundles.php');
    }

    public function testPagesWorkInBothStates(): void
    {
        $installed = class_exists(Client::class);
        $bundles = array_map(static fn (object $b): string => $b::class, static::$kernel->getBundles());
        self::assertSame($installed, \in_array(GmbReviewsBundle::class, $bundles, true));
        self::assertSame($installed ? [GmbReviewsBundle::class] : [], Kernel::addonBundles());
        self::assertSame(!$installed, static::getContainer()->has(GmbReviewsFallbackExtension::class), 'заглушки — лише без бандла');

        // у тестах токен заданий, а gbp-manager недоступний (127.0.0.1:9) — блоку нема в обох станах, сторінки працюють
        $home = $this->get($this->url('site_home', ['_locale' => self::defaultLocale()]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $home->filter('#store-reviews'));
        self::assertStringNotContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent());

        $product = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $product->filter('#reviews'), 'власні відгуки товару — без пакета теж');
        self::assertCount(0, $product->filter('.reviews-store'));
    }

    public function testFallbackFunctionsRenderNothing(): void
    {
        if (class_exists(Client::class)) {
            self::markTestSkipped('пакет встановлено — gmb_* дає бандл (GbpRatingTest)');
        }
        $twig = static::getContainer()->get('twig');
        $html = $twig->createTemplate("{{ gmb_reviews(null, {theme: 'site'}) }}|{{ gmb_rating_badge(null, {link: true}) }}|{{ gmb_reviews_jsonld() }}|{{ gmb_rating() is null ? 'n' }}|{{ gmb_aggregate_rating() is null ? 'n' }}|{{ gmb_reviews_data('x') is null ? 'n' }}")->render();
        self::assertSame('|||n|n|n', $html);
    }
}

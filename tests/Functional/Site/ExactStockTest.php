<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E13 PR-7, точні залишки й терміни: лише група з прапорцем exactStock бачить залишок у штуках на картці й у лістингу
 * (V1 5 + V2 3 = 8 для P1) і термін постачання варіанта під замовлення; гість і покупець без прапорця — «в наявності».
 */
final class ExactStockTest extends CatalogPageTestCase
{
    public function testOnlyExactStockGroupSeesNumbers(): void
    {
        $locale = self::defaultLocale();
        $this->fx->v['V5']->setLeadTimeDays(7);
        $exact = new CustomerGroup('exact_'.$this->suffix, 'Опт точний');
        $exact->setExactStock(true);
        $plain = new CustomerGroup('plain_'.$this->suffix, 'Опт звичайний');
        $wholesaler = new Customer('exact-'.$this->suffix.'@example.test', 'Оптовик', $locale);
        $wholesaler->setCustomerGroup($exact);
        $other = new Customer('plain-'.$this->suffix.'@example.test', 'Покупець', $locale);
        $other->setCustomerGroup($plain);
        foreach ([$exact, $plain, $wholesaler, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->reindex($this->fx->productIds());
        $productUrl = $this->productUrl($this->fx->p['P1'], $locale);
        $listingUrl = $this->categoryUrl($this->fx->shoes, $locale);

        // гість: жодного числа
        $crawler = $this->get($productUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.stock-exact'));
        self::assertStringNotContainsString('Залишок', $crawler->filter('.buy-box')->text());
        $crawler = $this->get($listingUrl);
        self::assertCount(0, $crawler->filter('.stock-exact'));

        // покупець групи без прапорця — як гість
        $this->client->loginUser($other, 'site');
        $crawler = $this->get($productUrl);
        self::assertCount(0, $crawler->filter('.stock-exact'));
        $crawler = $this->get($listingUrl);
        self::assertCount(0, $crawler->filter('.stock-exact'));

        // оптовик з прапорцем: залишок обраного варіанта на картці, сума по товару в лістингу, термін під замовлення
        $this->client->loginUser($wholesaler, 'site');
        $crawler = $this->get($productUrl);
        self::assertResponseIsSuccessful();
        self::assertSame("Залишок: 5\u{a0}шт", trim($crawler->filter('.buy-box .stock-exact')->text()));
        $crawler = $this->get($listingUrl);
        $cards = [];
        $crawler->filter('li.card')->each(static function (Crawler $card) use (&$cards): void {
            $cards[(string) $card->attr('data-product-id')] = $card;
        });
        self::assertSame('Залишок: 8', trim($cards[(string) $this->fx->p['P1']->getId()]->filter('.stock-exact')->text()));
        $p3 = $cards[(string) $this->fx->p['P3']->getId()];
        self::assertSame('Залишок: 0', trim($p3->filter('.stock-exact')->text()), 'P3: V4 зі складу 0');
        self::assertStringContainsString('7 днів', $p3->filter('div[class^="stock-"]')->text(), 'і термін V5 під замовлення поруч, незалежно від залишку');
        $crawler = $this->get($this->productUrl($this->fx->p['P3'], $locale, ['size' => 's41']));
        self::assertStringContainsString('7 днів', $crawler->filter('.buy-box p[class^="stock-"]')->text(), 'термін варіанта під замовлення');
    }
}

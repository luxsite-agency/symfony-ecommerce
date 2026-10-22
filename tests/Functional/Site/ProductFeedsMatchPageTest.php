<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Site\Feed\FeedChannel;
use App\Site\Feed\MerchantFeedGenerator;
use DateTimeImmutable;
use SimpleXMLElement;

/**
 * Кожен item фідів Google і Meta проти його сторінки: g:id = item_id події view_item у dataLayer (за ним динамічний
 * ремаркетинг Google Ads і Meta знаходить товар у каталозі) і ціна до сплати у фіді = ціна на сторінці (Pricing: акції,
 * ручна стара ціна) — розбіжність Merchant Center і Meta відхиляють.
 */
final class ProductFeedsMatchPageTest extends CatalogPageTestCase
{
    public function testEveryFeedItemMatchesItsPageItemIdAndPrice(): void
    {
        $promotion = new Promotion('Мінус 10', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->setEndsAt(new DateTimeImmutable('today +3 days'));
        $promotion->addProduct($this->fx->p['P6']);
        $this->em()->persist($promotion);
        $this->em()->flush();

        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::GA4_MEASUREMENT_ID, 'G-ABC1234567');
        try {
            $locale = self::defaultLocale();
            $result = static::getContainer()->get(MerchantFeedGenerator::class)->generate($locale);
            $checked = 0;
            foreach (FeedChannel::cases() as $channel) {
                $xml = new SimpleXMLElement((string) file_get_contents($result['paths'][$channel->value]));
                foreach ($xml->channel->item as $item) {
                    $g = $item->children(MerchantFeedGenerator::NS);
                    $id = (string) $g->id;
                    if (!str_ends_with($id, $this->suffix)) {
                        continue; // товари інших тестів у спільній БД
                    }
                    $link = (string) $item->link;
                    $query = parse_url($link, \PHP_URL_QUERY);
                    $crawler = $this->get((string) parse_url($link, \PHP_URL_PATH).(null !== $query ? '?'.$query : ''));
                    self::assertResponseIsSuccessful($link);
                    $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
                    self::assertSame('view_item', $events[0]['event']);
                    $event = $events[0]['ecommerce']['items'][0];
                    self::assertSame($id, $event['item_id'], $channel->value.': g:id = item_id у dataLayer');

                    $pay = (string) ('' !== (string) $g->sale_price ? $g->sale_price : $g->price);
                    self::assertSame(\sprintf('%.2f UAH', $event['price']), $pay, $channel->value.' '.$id.': ціна до сплати = ціна на сторінці');
                    ++$checked;
                }
            }
            self::assertSame(2 * 6, $checked, 'P1 (2 варіанти), P2, P3 (2), P6 — у кожному з двох каналів');

            $p6 = $this->feedItem($result['paths']['google'], 'P6-'.$this->suffix);
            self::assertSame('30.00 UAH', (string) $p6->price);
            self::assertSame('27.00 UAH', (string) $p6->sale_price, 'акція з Pricing, як на сторінці');
        } finally {
            $settings->invalidate();
        }
    }

    private function feedItem(string $path, string $id): SimpleXMLElement
    {
        $xml = new SimpleXMLElement((string) file_get_contents($path));
        foreach ($xml->channel->item as $item) {
            $g = $item->children(MerchantFeedGenerator::NS);
            if ($id === (string) $g->id) {
                return $g;
            }
        }
        self::fail('Нема item '.$id);
    }
}

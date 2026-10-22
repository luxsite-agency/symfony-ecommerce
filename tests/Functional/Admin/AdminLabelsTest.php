<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentStatus;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;

/** Аудит UX 18.10, PR-2: людські підписи замість кодів і «Null», історія замовлення фразами, назви мовою адмінки, дії «Додати …». */
final class AdminLabelsTest extends AdminWebTestCase
{
    public function testOrderCardSpeaksHumanAndPaymentStatusStillSaves(): void
    {
        $product = new Product('LBL-1', 'UAH');
        $product->translate('uk')->setName('Двері підписи');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('3');
        $this->em()->persist($product);
        $this->em()->flush();
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Покупець', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '1']]));

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/order/%d', $order->getId())));
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        foreach (['Не оплачено', 'Швидка заявка', 'Узгодить менеджер'] as $label) {
            self::assertStringContainsString($label, $text);
        }
        foreach (['Pending', 'Quick', 'ManagerContact', 'manager_contact', 'Null', 'place:'] as $raw) {
            self::assertStringNotContainsString($raw, $text, "сирий код «{$raw}»");
        }
        self::assertStringContainsString('Швидка заявка покупця: Нове', $crawler->filter('[data-history]')->first()->text());
        self::assertStringContainsString('покупець', $crawler->filter('[data-history]')->first()->text());

        $this->client->submit($crawler->filter('button[data-transition="confirm"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Нове → Підтверджене', $crawler->filter('[data-history]')->last()->text());
        self::assertStringContainsString('менеджер user@example.test', $crawler->filter('[data-history]')->last()->text());

        // форма: дата текстом у форматі адмінки, оплата зберігається (EnumType з enum-підписами)
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/order/%d/edit', $order->getId())));
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', (string) $crawler->filter('input[name="Order[placedAt]"]')->attr('value'));
        self::assertSame('Оплачено', trim($crawler->filter('select[name="Order[paymentStatus]"] option[value="paid"]')->text()));
        $form = $crawler->selectButton('Зберегти')->form();
        $form['Order[paymentStatus]'] = 'paid';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(PaymentStatus::Paid, $this->em()->find(Order::class, $order->getId())?->getPaymentStatus());
    }

    public function testCategoryNamesFollowAdminLocaleAndAddActionIsGrammatical(): void
    {
        $category = new Category();
        $category->translate('ru')->setName('Двери')->setSlug('dveri-ru');
        $category->translate('uk')->setName('Двері')->setSlug('dveri');
        $child = new Category($category);
        $child->translate('ru')->setName('Межкомнатные')->setSlug('mezhkomnatnye');
        $child->translate('uk')->setName('Міжкімнатні')->setSlug('mizhkimnatni');
        $this->em()->persist($category);
        $this->em()->persist($child);
        $this->em()->flush();
        $this->em()->clear();

        $this->loginAs(UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/category'));
        self::assertResponseIsSuccessful();
        $list = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('Двері / Міжкімнатні', $list, 'назви мовою адмінки (uk), хоч ru-переклад створено першим');
        self::assertStringNotContainsString('Межкомнатные', $list);
        self::assertSame('Додати категорію', trim($crawler->filter('.global-actions a.action-new')->text()));

        $crawler = $this->client->request('GET', $this->adminUrl('/category/new'));
        self::assertSame('Нова категорія', trim($crawler->filter('h1')->text()));

        $crawler = $this->client->request('GET', $this->adminUrl('/category?_locale=ru'));
        self::assertStringContainsString('Двери / Межкомнатные', $crawler->filter('table tbody')->text(), 'адмінка ru — ru-назви');
        $this->client->request('GET', $this->adminUrl('/?_locale=uk'));
    }

    public function testMenuButtonLoginLangAndSettingsLabels(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertSame('uk', $crawler->filter('html')->attr('lang'), 'вхід — з lang');

        $this->loginAs(UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertSame('Відкрити меню', $crawler->filter('#navigation-toggler')->attr('aria-label'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Toggle navigation', $html);
        $list = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('Ідентифікатор контейнера Google Tag Manager', $list);
        self::assertStringContainsString('analytics.gtm_container_id', $list, 'технічний ключ — дрібно під назвою');
        self::assertStringNotContainsString('GTM container id', $list);
        self::assertStringNotContainsString('Порожньо', $list, 'порожнє значення — тире, стан — у колонці «Стан»');
    }
}

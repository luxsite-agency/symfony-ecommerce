<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\Controller\Crud\ProductCrudController;
use App\Core\Catalog\Entity\Product;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\Entity\Setting;
use App\Core\Shared\Money\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Аудит UX 18.10, PR-3: таблиці й списки адмінки, порожні стани, форма товару, налаштування не пишуть журнал при перегляді. */
final class AdminListsUxTest extends AdminWebTestCase
{
    public function testOrderListAndCardActions(): void
    {
        $product = $this->product('UX3-1');
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Checkout, 'uk', 'Покупець', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '1']]));

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/order'));
        self::assertNotContains('ID', $crawler->filter('table thead th')->each(static fn ($n): string => trim($n->text())), 'колонку ID прибрано — дублює номер');
        self::assertStringContainsString('text-nowrap', (string) $crawler->filter('td[data-column="number"]')->attr('class'));
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', trim($crawler->filter('td[data-column="placedAt"]')->text()), 'дата без секунд');
        self::assertStringEndsWith(\sprintf('/order/%d', $order->getId()), (string) $crawler->filter('tr[data-default-action-url]')->attr('data-default-action-url'), 'клік по рядку — картка');

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/order/%d', $order->getId())));
        $cancel = $crawler->filter('button[data-transition="cancel"]');
        self::assertSame('btn btn-outline-danger', $cancel->attr('class'));
        self::assertStringContainsString('Скасувати замовлення', $cancel->text());
        self::assertStringContainsString('confirm(', (string) $cancel->closest('form')?->attr('onsubmit'), 'скасування — з підтвердженням');
        self::assertSame('btn btn-primary', $crawler->filter('button[data-transition="confirm"]')->attr('class'));
        $order_of = $crawler->filter('.page-actions [data-transition], .page-actions [data-action-name="edit"]')->each(static fn ($n): string => (string) ($n->attr('data-transition') ?? 'edit'));
        self::assertSame(['cancel', 'confirm', 'edit'], $order_of, 'скасування першим, наступний крок поруч із «Редагувати»');
    }

    public function testSettingsViewDoesNotWriteAuditAndAuditListIsReadable(): void
    {
        $this->loginAs(UserRole::Admin);
        $db = $this->em()->getConnection();
        $db->executeStatement('DELETE FROM setting');
        $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, \count($this->em()->getRepository(Setting::class)->findAll()), 'рядки налаштувань створено');
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM admin_audit_entry WHERE entity_type = 'setting'"), 'перегляд — не дія користувача (A13)');

        $product = $this->product('UX3-2');
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/product/%d/edit', $product->getId())));
        self::assertSame('Товар UX3-2', trim($crawler->filter('h1')->text()), 'h1 форми — назва товару');
        $form = $crawler->filter('form.ea-edit-form, form[name="Product"]')->form();
        $form['Product[translationsForm][uk][name]'] = 'Товар UX3-2 новий';
        $this->client->submit($form);

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'));
        self::assertSame(['Коли', 'Хто', 'Джерело', 'Дія', 'Що', 'Зміна'], \array_slice(array_values(array_filter($crawler->filter('table thead th')->each(static fn ($n): string => trim($n->text())))), 0, 6), '6 колонок замість 11 (+ меню дій)');
        self::assertStringContainsString('Назва (uk): Товар UX3-2 → Товар UX3-2 новий', $crawler->filter('table tbody')->text());
    }

    public function testEmptyStatesExplainWhereDataComesFrom(): void
    {
        $this->loginAs(UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/product-review'));
        self::assertStringContainsString('Відгуків ще немає', $crawler->filter('[data-empty-state="ProductReview"]')->text());
        self::assertCount(0, $crawler->filter('tr.empty-row'), 'без сірих рядків-заглушок');

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        self::assertCount(1, $crawler->filter('[data-empty-state="AdminApiToken"] a[href$="/admin-api-token/issue"]'), 'дія — створити токен');

        $crawler = $this->client->request('GET', $this->adminUrl('/product-review?query=немає-такого'));
        self::assertResponseIsSuccessful('пошук у відгуках — без 500 (подвійний аліас customer)');
        self::assertCount(1, $crawler->filter('[data-empty-state="search"]'));
        self::assertStringContainsString('Скинути пошук і фільтри', $crawler->filter('[data-empty-state="search"]')->text());

        // той самий подвійний аліас був у заявках оптовиків і компаніях
        foreach (['/wholesale-application', '/company'] as $list) {
            $this->client->request('GET', $this->adminUrl($list.'?query=немає-такого'));
            self::assertResponseIsSuccessful("пошук {$list}");
        }
    }

    public function testVariantTitleAndTokenScopes(): void
    {
        $product = $this->product('UX3-3');
        self::assertSame('UX3-3 · 1 500,00 ₴ · 3 шт', str_replace(["\u{a0}", "\u{202f}"], ' ', ProductCrudController::variantTitle($product->getDefaultVariant(), static::getContainer()->get(TranslatorInterface::class), 'uk')), 'SKU, ціна, залишок з одиницею');

        $this->loginAs(UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token/issue'));
        $first = $crawler->filter('#api-token-issue .form-check-label')->first();
        self::assertStringStartsWith('Читання каталогу', trim($first->text()), 'людська назва дозволу першою');
        self::assertSame('catalog:read', $first->filter('code')->text());
        self::assertCount(1, $crawler->filter('.page-actions button[form="api-token-issue"]'), 'кнопки — у шапці, як на інших формах');
    }

    private function product(string $sku): Product
    {
        $product = new Product($sku, 'UAH');
        $product->translate('uk')->setName('Товар '.$sku);
        $product->getDefaultVariant()->setPrice(Money::of(150_000, 'UAH'));
        $product->getDefaultVariant()->setStock('3');
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Cart\CartMerger;
use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Кошик і кабінет (E12 PR-3, консиліум 07.10 П4): гостьовий кошик зливається з кошиком покупця при вході поштучно
 * (неактивні рядки пропускаються), токен ротується, чужий кошик за cookie ігнорується, вихід знімає cookie,
 * замовлення очищає кошик покупця. Гість без входу — як раніше (CartTest).
 */
final class CartMergeTest extends CatalogPageTestCase
{
    private const string PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
    }

    public function testGuestCartMergesIntoCustomerCartAndTokenRotates(): void
    {
        $customer = $this->customer('merge');
        // у покупця вже є кошик: V2 ×1
        $own = new Cart(Cart::hashToken(bin2hex(random_bytes(32))), 'UAH');
        $own->assignTo($customer);
        $own->addItem($this->fx->v['V2'], '1');
        $this->em()->persist($own);
        $this->em()->flush();

        // гість кладе V1 ×2, V2 ×1 і V3 (P2), який до входу стане неактивним
        $this->add('V1', '2');
        $this->add('V2', '1');
        $this->add('V3', '1');
        $guestToken = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        $guestCart = $this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($guestToken)]);
        self::assertNotNull($guestCart);
        $guestCartId = $guestCart->getId();
        // після запитів ядро перезавантажене — сутності фікстури відчеплені, вимикаємо товар через свіжий EM
        $this->em()->find(Product::class, $this->fx->id('P2'))?->setActive(false);
        $this->em()->flush();

        $this->login($customer->getEmail());
        self::assertResponseRedirects('/account/');
        $token = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        self::assertNotSame($guestToken, $token, 'токен кошика ротовано при вході');
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($guestToken)]), 'старий токен більше нічого не відкриває');
        self::assertNull($this->em()->find(Cart::class, $guestCartId), 'гостьовий кошик видалено після злиття');
        $merged = $this->em()->getRepository(Cart::class)->findOneBy(['customer' => $customer->getId()]);
        self::assertNotNull($merged);
        self::assertSame($own->getId(), $merged->getId(), 'кошик покупця той самий рядок');
        self::assertSame(Cart::hashToken($token), $merged->getTokenHash());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('не перенесені: 1', $crawler->filter('.flash-error')->text(), 'неактивний рядок пропущено з повідомленням');
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        $quantities = [];
        foreach ($crawler->filter('tr.cart-line') as $line) {
            $node = new \Symfony\Component\DomCrawler\Crawler($line);
            $quantities[(string) $node->attr('data-sku')] = (string) $node->filter('input[name="quantity"]')->attr('value');
        }
        ksort($quantities);
        self::assertSame(['P1-'.$this->suffix => '2', 'V2-'.$this->suffix => '2'], $quantities, 'V1 ×2 перенесено, V2 1+1, V3 (неактивний товар) пропущено');
    }

    public function testGuestCartBecomesCustomerCartWhenCustomerHasNone(): void
    {
        $customer = $this->customer('adopt');
        $this->add('V1', '1');
        $guestToken = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        $guestCartId = $this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($guestToken)])?->getId();

        $this->login($customer->getEmail());
        self::assertResponseRedirects('/account/');
        $this->em()->clear();
        $cart = $this->em()->getRepository(Cart::class)->findOneBy(['customer' => $customer->getId()]);
        self::assertNotNull($cart);
        self::assertSame($guestCartId, $cart->getId(), 'гостьовий кошик став кошиком покупця');
        self::assertNotSame(Cart::hashToken($guestToken), $cart->getTokenHash(), 'токен ротовано');
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertCount(1, $crawler->filter('tr.cart-line'));
    }

    public function testForeignCartTokenIsIgnoredForGuestAndForOtherCustomer(): void
    {
        $alice = $this->customer('alice');
        $bob = $this->customer('bob');
        $bobToken = bin2hex(random_bytes(32));
        $bobCart = new Cart(Cart::hashToken($bobToken), 'UAH');
        $bobCart->assignTo($bob);
        $bobCart->addItem($this->fx->v['V1'], '3');
        $this->em()->persist($bobCart);
        $this->em()->flush();

        // гість з токеном Боба — порожній кошик, у cart.json нічого
        $this->client->getCookieJar()->set(new Cookie(CartResolver::COOKIE, $bobToken, null, '/', 'localhost'));
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.empty');
        $this->client->request('GET', 'http://localhost/cart.json');
        self::assertSame(0, json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['count']);

        // гість з токеном Боба кладе товар — це НОВИЙ гостьовий кошик, кошик Боба не змінюється
        $this->add('V2', '1');
        $this->em()->clear();
        self::assertCount(1, $this->em()->find(Cart::class, $bobCart->getId())?->getItems() ?? []);
        self::assertNotSame($bobToken, $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue());

        // Аліса входить із cookie Боба — отримує свій кошик (злиття чужого не відбувається), кошик Боба цілий
        $this->client->getCookieJar()->set(new Cookie(CartResolver::COOKIE, $bobToken, null, '/', 'localhost'));
        $this->login($alice->getEmail());
        self::assertResponseRedirects('/account/');
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty', 'у Аліси свого кошика нема — кошик Боба їй не показується');
        $this->em()->clear();
        $bobCart = $this->em()->find(Cart::class, $bobCart->getId());
        self::assertNotNull($bobCart);
        self::assertSame($bob->getId(), $bobCart->getCustomerId());
        self::assertSame(Cart::hashToken($bobToken), $bobCart->getTokenHash(), 'токен Боба не ротовано');
        self::assertCount(1, $bobCart->getItems());
    }

    public function testLogoutDropsCookieKeepsCartAndOrderClearsCustomerCart(): void
    {
        $customer = $this->customer('order');
        $this->login($customer->getEmail());
        $this->add('V1', '2');
        $token = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        self::assertNotSame('', $token);
        $this->em()->clear();
        $cart = $this->em()->getRepository(Cart::class)->findOneBy(['customer' => $customer->getId()]);
        self::assertNotNull($cart, 'кошик залогіненого одразу з customer_id');
        $cartId = $cart->getId();

        // вихід: cookie знято, кошик у БД лишився; гість нічого не бачить
        $crawler = $this->client->request('GET', 'http://localhost/account/');
        $this->client->submit($crawler->filter('form[action$="/account/logout"]')->form());
        self::assertResponseRedirects();
        self::assertNull($this->client->getCookieJar()->get(CartResolver::COOKIE), 'cookie кошика знято при виході');
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty');
        $this->em()->clear();
        self::assertCount(1, $this->em()->find(Cart::class, $cartId)?->getItems() ?? []);

        // навіть зі старою cookie після виходу — порожньо (кошик належить покупцю)
        $this->client->getCookieJar()->set(new Cookie(CartResolver::COOKIE, $token, null, '/', 'localhost'));
        $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty');

        // повторний вхід — товари на місці, кошик той самий
        $this->login($customer->getEmail());
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertCount(1, $crawler->filter('tr.cart-line'));

        // замовлення → кошик покупця порожній у БД, а не лише без cookie
        $crawler = $this->client->request('GET', 'http://localhost/checkout/');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Покупець', 'phone' => '0671234568', 'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $this->em()->clear();
        $cart = $this->em()->find(Cart::class, $cartId);
        self::assertNotNull($cart);
        self::assertTrue($cart->isEmpty(), 'після замовлення кошик покупця очищено');
        $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty');
    }

    public function testExpiredCustomerCartIsClearedAndExpiredGuestCartIsIgnoredOnLogin(): void
    {
        // прострочений кошик покупця (V2) перед злиттям порожніє, гостьовий V1 переноситься
        $customer = $this->customer('stale');
        $own = new Cart(Cart::hashToken(bin2hex(random_bytes(32))), 'UAH');
        $own->assignTo($customer);
        $own->addItem($this->fx->v['V2'], '5');
        $this->em()->persist($own);
        $this->em()->flush();
        $this->expire($own);
        $this->add('V1', '1');
        $this->login($customer->getEmail());
        self::assertResponseRedirects('/account/');
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertSame(['P1-'.$this->suffix], $crawler->filter('tr.cart-line')->extract(['data-sku']), 'прострочений V2 зник, гостьовий V1 перенесено');
        $this->em()->clear();
        self::assertFalse($this->em()->find(Cart::class, $own->getId())?->isExpired(), 'строк кошика покупця подовжено');

        // прострочений гостьовий кошик — як відсутній: кошик покупця без змін, гостьовий рядок не видаляється
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'http://localhost/account/');
        $other = $this->customer('fresh');
        $this->add('V2', '3');
        $guestToken = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        $guest = $this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($guestToken)]);
        self::assertNotNull($guest);
        $this->expire($guest);
        $this->login($other->getEmail());
        self::assertResponseRedirects('/account/');
        self::assertNull($this->client->getCookieJar()->get(CartResolver::COOKIE), 'кошика нема ні в гостя (прострочений), ні в покупця — cookie знято');
        $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Cart::class, $guest->getId()), 'прострочений гостьовий кошик лишається для cleanup');
        self::assertNull($this->em()->getRepository(Cart::class)->findOneBy(['customer' => $other->getId()]));
    }

    public function testMergeFailureDoesNotBreakLogin(): void
    {
        $customer = $this->customer('boom');
        $this->add('V1', '1');
        $guestToken = (string) $this->client->getCookieJar()->get(CartResolver::COOKIE)?->getValue();
        $this->client->disableReboot(); // один контейнер: підмінений CartMerger має дожити до POST входу
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willThrowException(new RuntimeException('lock backend down'));
        static::getContainer()->set(CartMerger::class, new CartMerger(static::getContainer()->get(EntityManagerInterface::class), static::getContainer()->get(ManagerRegistry::class), $locks));

        $this->login($customer->getEmail());
        self::assertResponseRedirects('/account/', null, 'вхід відбувся попри збій злиття');
        self::assertNull($this->client->getCookieJar()->get(CartResolver::COOKIE), 'cookie кошика знято');
        $crawler = $this->client->request('GET', 'http://localhost/account/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($customer->getEmail(), $crawler->text());
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($guestToken)]), 'гостьовий кошик не зачеплено');
    }

    private function expire(Cart $cart): void
    {
        $this->em()->createQuery('UPDATE '.Cart::class.' c SET c.expiresAt = :past WHERE c.id = :id')
            ->setParameter('past', new DateTimeImmutable('-1 minute'))->setParameter('id', $cart->getId())->execute();
        $this->em()->clear();
    }

    private function add(string $variant, string $quantity): void
    {
        $this->client->request('POST', 'http://localhost/cart/add', ['variant' => (string) $this->fx->v[$variant]->getId(), 'quantity' => $quantity, '_token' => 'csrf-token']);
        self::assertResponseRedirects('/cart/');
    }

    private function login(string $email): void
    {
        $crawler = $this->client->request('GET', 'http://localhost/account/login');
        $this->client->submit($crawler->filter('form.account-form')->form(['email' => $email, 'password' => self::PASSWORD]));
    }

    private function customer(string $name): Customer
    {
        $customer = new Customer($name.'-'.$this->suffix.'@example.test', 'Покупець', self::defaultLocale());
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }
}

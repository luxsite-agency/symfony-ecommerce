<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Account\AccountDeletion;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Customer\Entity\ViewedProduct;
use App\Core\Customer\Entity\WishlistItem;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Message\ExportCustomerData;
use App\Core\Customer\Message\PurgeUnverifiedCustomers;
use App\Core\Customer\Message\SendCustomerAuthMail;
use App\Core\Customer\MessageHandler\ExportCustomerDataHandler;
use App\Core\Customer\MessageHandler\PurgeUnverifiedCustomersHandler;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E12 PR-6, персональні дані: згода при реєстрації в журналі з версією й хешем; експорт — пароль, файл у фоні, лист,
 * одноразове посилання лише для цього покупця; видалення — пароль, ідентичність стерта, вхід закрито, адреси
 * видалені, замовлення без привʼязки, сесія вийшла, лист-підтвердження.
 */
final class PrivacyTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $base;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->base = 'https://'.$host;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254)); // ліміти листів з IP спільні на процес
    }

    public function testRegistrationRequiresAndLogsPdConsent(): void
    {
        $email = 'pd-'.bin2hex(random_bytes(4)).'@example.test';
        $crawler = $this->client->request('GET', $this->base.'/account/register');
        self::assertCount(0, $crawler->filter('input[name="registration[consent]"][checked]'), 'чекбокс не поставлено наперед');
        $this->client->submit($crawler->filter('form.account-form')->form(['registration[email]' => $email]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('згода', $this->client->getCrawler()->filter('.form-error')->text());
        self::assertNull($this->em->getRepository(Customer::class)->findOneBy(['email' => $email]));

        $form = $crawler->filter('form.account-form')->form(['registration[email]' => $email]);
        $consent = $form['registration[consent]'];
        self::assertInstanceOf(ChoiceFormField::class, $consent);
        $consent->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($this->base.'/account/login');
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]) ?? self::fail();
        $consents = static::getContainer()->get(Consents::class);
        $history = $consents->history($customer);
        self::assertCount(1, $history);
        self::assertSame(['pd_processing', true, 'register', $consents->policyVersion(), $consents->policyHash(ConsentPurpose::PdProcessing, 'uk')], [
            $history[0]->getPurpose()->value, $history[0]->isGranted(), $history[0]->getSource(), $history[0]->getPolicyVersion(), $history[0]->getPolicyHash(),
        ]);
    }

    public function testExportNeedsPasswordBuildsFileAndLinkIsOneTimeAndPersonal(): void
    {
        $me = $this->customer();
        $this->order($me);
        $this->client->loginUser($me, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/privacy/export');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.export-form')->form(['reauth[current]' => 'wrong']));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->async());
        $this->client->submit($this->client->getCrawler()->filter('form.export-form')->form(['reauth[current]' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/privacy');
        $jobs = $this->async();
        self::assertCount(1, $jobs);
        self::assertCount(0, $this->sentMails(), 'лист лише коли файл готовий');

        // посилання є, файл ще готується — 202 без витрати токена
        $this->client->request('GET', $jobs[0]->mailUrl);
        self::assertResponseStatusCodeSame(202);

        static::getContainer()->get(ExportCustomerDataHandler::class)($jobs[0]);
        $mail = $this->sentMails()[0] ?? null;
        self::assertSame([SendCustomerAuthMail::EXPORT, $me->getEmail(), $jobs[0]->mailUrl], [$mail?->kind, $mail?->email, $mail?->url]);

        // чужий покупець з тим самим посиланням — 404, файл на місці
        $this->client->loginUser($this->customer(), 'site');
        $this->client->request('GET', $mail->url);
        self::assertResponseStatusCodeSame(410);

        // власник: GET — лише кнопка (превʼю поштовика чи prefetch не зʼїдає посилання; рев'ю #60 v2, L1), POST — файл
        $this->client->loginUser($this->fresh($me), 'site');
        $crawler = $this->client->request('GET', $mail->url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.export-download'));
        $this->client->request('GET', $mail->url);
        self::assertResponseIsSuccessful('повторний GET — файл і токен на місці');
        $this->client->submit($crawler->filter('form.export-download')->form());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($me->getEmail(), $data['profile']['email']);
        self::assertCount(1, $data['orders']);
        self::assertSame('+380670000001', $data['orders'][0]['phone']);
        self::assertArrayHasKey('consents', $data);
        $this->client->request('GET', $mail->url);
        self::assertResponseStatusCodeSame(410, 'одноразове: файл забрано, токен використано');
    }

    public function testDeletionErasesIdentityKeepsOrdersAndLogsOut(): void
    {
        $me = $this->customer();
        $email = $me->getEmail();
        $order = $this->order($me);
        $address = new CustomerAddress($me);
        $address->fill('Дім', 'Покупець', '0671234567', ShippingMethod::Courier, 'Київ', 'вул. Хрещатик, 1', null);
        $this->em->persist($address);
        static::getContainer()->get(Consents::class)->record($me, ConsentPurpose::PdProcessing, true, 'register');
        $product = new Product('DEL-'.bin2hex(random_bytes(3)), 'UAH');
        $product->translate('uk')->setName('Товар видалення')->setSlug('del-'.bin2hex(random_bytes(3)));
        $this->em->persist($product);
        $this->em->persist(new WishlistItem($me, $product));
        $this->em->persist(new ViewedProduct($me, $product));
        $cart = new Cart(hash('sha256', 'cart-'.$me->getEmail()), 'UAH');
        $cart->assignTo($me);
        $this->em->persist($cart);
        $this->em->flush();
        $cartId = $cart->getId();
        $this->client->loginUser($me, 'site');

        $crawler = $this->client->request('GET', $this->base.'/account/privacy/delete');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.delete-form')->form(['reauth[current]' => 'wrong']));
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->fresh($me)->isActive());

        $crawler = $this->client->submit($this->client->getCrawler()->filter('form.delete-form')->form(['reauth[current]' => 'correct horse battery']));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.account-deleted'));
        $mail = $this->sentMails()[0] ?? null;
        self::assertSame([SendCustomerAuthMail::DELETED, $email], [$mail?->kind, $mail?->email]);

        $fresh = $this->fresh($me);
        self::assertTrue($fresh->isDeleted());
        self::assertSame(['', null, null, false, ''], [$fresh->getEmail(), $fresh->getPhone(), $fresh->getPassword(), $fresh->isActive(), $fresh->getName()]);
        self::assertCount(0, $this->em->getRepository(CustomerAddress::class)->findBy(['customer' => $me->getId()]), 'адреси видалено');
        self::assertCount(0, $this->em->getRepository(WishlistItem::class)->findBy(['customer' => $me->getId()]), 'обране видалено (рев\'ю #60 v2, L2)');
        self::assertCount(0, $this->em->getRepository(ViewedProduct::class)->findBy(['customer' => $me->getId()]), 'переглянуті видалено');
        self::assertNull(($this->em->find(Cart::class, $cartId) ?? self::fail())->getCustomer(), 'кошик відвʼязано');
        $kept = $this->em->find(Order::class, $order->getId()) ?? self::fail();
        self::assertNull($kept->getCustomer(), 'замовлення лишається без привʼязки');
        self::assertSame('+380670000001', $kept->getPhone(), 'снепшот замовлення не чіпається');
        $consents = static::getContainer()->get(Consents::class);
        self::assertFalse($consents->isGranted($fresh, ConsentPurpose::PdProcessing));
        self::assertSame(['register', 'delete'], array_map(static fn ($c): string => $c->getSource(), $consents->history($fresh)), 'надано при реєстрації, відкликано при видаленні');

        // сесія вийшла, вхід старими даними закритий
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
        $crawler = $this->client->request('GET', $this->base.'/account/login');
        $this->client->submit($crawler->filter('form.account-form')->form(['email' => $email, 'password' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/login');

        // адресу може зареєструвати знову той самий власник: email у видаленого стерто (не зайнятий)
        $crawler = $this->client->request('GET', $this->base.'/account/register');
        $form = $crawler->filter('form.account-form')->form(['registration[email]' => $email]);
        $consent = $form['registration[consent]'];
        self::assertInstanceOf(ChoiceFormField::class, $consent);
        $consent->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($this->base.'/account/login');
        self::assertSame(SendCustomerAuthMail::VERIFY, $this->sentMails()[0]->kind ?? null, 'нова реєстрація, а не «акаунт уже є»');
    }

    /** Рев'ю #60, п. 1: видалений акаунт не є «неперевіреним» — нічний purge лишає рядок і журнал згод. */
    public function testNightlyPurgeKeepsDeletedAccountsAndTheirConsentLog(): void
    {
        $me = $this->customer();
        static::getContainer()->get(Consents::class)->record($me, ConsentPurpose::PdProcessing, true, 'register');
        $this->em->flush();
        static::getContainer()->get(AccountDeletion::class)->delete($me);
        $this->em->getConnection()->executeStatement('UPDATE customer SET created_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $me->getId()]);
        $unverified = new Customer('old-'.bin2hex(random_bytes(4)).'@example.test', '', 'uk');
        $this->em->persist($unverified);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE customer SET created_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $unverified->getId()]);

        static::getContainer()->get(PurgeUnverifiedCustomersHandler::class)(new PurgeUnverifiedCustomers());
        $this->em->clear();
        self::assertNull($this->em->find(Customer::class, $unverified->getId()), 'справді неперевірений — видалено');
        $kept = $this->em->find(Customer::class, $me->getId());
        self::assertNotNull($kept, 'видалений акаунт лишається');
        self::assertTrue($kept->isDeleted());
        self::assertCount(2, static::getContainer()->get(Consents::class)->history($kept), 'згоди: надано при реєстрації, відкликано при видаленні');
    }

    private function customer(): Customer
    {
        $customer = new Customer('priv-'.bin2hex(random_bytes(4)).'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    private function order(Customer $customer): Order
    {
        $order = new Order('T'.substr(bin2hex(random_bytes(6)), 0, 11), OrderSource::Checkout, 'uk', 'Покупець', '+380670000001', 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $order->setEmail($customer->getEmail());
        $order->setCustomer($customer, null);
        $order->recalculateTotals();
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function fresh(Customer $customer): Customer
    {
        $this->em->clear();

        return $this->em->find(Customer::class, $customer->getId()) ?? self::fail();
    }

    /** @return list<ExportCustomerData> */
    private function async(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof ExportCustomerData));
    }

    /** @return list<SendCustomerAuthMail> листи останнього запиту */
    private function sentMails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.auth_mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SendCustomerAuthMail));
    }
}

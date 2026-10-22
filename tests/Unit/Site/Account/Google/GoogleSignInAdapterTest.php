<?php

declare(strict_types=1);

namespace App\Tests\Unit\Site\Account\Google;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Google\GoogleSignInConfig;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Site\Account\Google\GoogleSignInAdapter;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Адаптер контракту A↔D (відгуки): гостю — старт із next, покупцю без прив'язки — сторінка прив'язки, без ключів — null. */
final class GoogleSignInAdapterTest extends TestCase
{
    public function testGuestGetsStartPathWithNext(): void
    {
        $adapter = $this->adapter(true, null);
        self::assertTrue($adapter->isAvailable());
        self::assertSame('site_account_google_start?next=/account/reviews/new?product=5&_locale=ru', $adapter->startPath('/account/reviews/new?product=5', 'ru'));
    }

    public function testCustomerWithoutGoogleGetsLinkPageAndLinkedOneStartsAsUsual(): void
    {
        $customer = new Customer('a@example.test', 'A', 'uk');
        $customer->verifyEmail('hash');
        $adapter = $this->adapter(true, $customer);
        self::assertFalse($adapter->isLinked($customer));
        self::assertSame('site_account_google_link?_locale=uk', $adapter->startPath('/x', 'uk'));

        $customer->linkGoogle('sub-1', 'G@Example.test');
        self::assertTrue($adapter->isLinked($customer));
        self::assertSame('g@example.test', $customer->getGoogleEmail());
        self::assertSame('site_account_google_start?next=/x&_locale=uk', $adapter->startPath('/x', 'uk'));
    }

    public function testWithoutKeysNothing(): void
    {
        $adapter = $this->adapter(false, null);
        self::assertFalse($adapter->isAvailable());
        self::assertNull($adapter->startPath('/x', 'uk'));
    }

    private function adapter(bool $envKeys, ?Customer $customer): GoogleSignInAdapter
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($customer);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->cookies->set('MOCKSESSID', 'x'); // hasPreviousSession(): CustomerContext читає токен лише з сесією
        $requests = new RequestStack();
        $requests->push($request);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params): string => $route.'?'.urldecode(http_build_query($params)));
        // налаштувань у БД нема (перемикач вимкнений) → ключі лише з env
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $settings = new Settings($em, new ArrayAdapter(), new SecretBox(), new NullLogger());

        return new GoogleSignInAdapter(new GoogleSignInConfig($settings, $envKeys ? 'id' : '', $envKeys ? 'secret' : ''), new CustomerContext($security, $requests), $urls);
    }
}

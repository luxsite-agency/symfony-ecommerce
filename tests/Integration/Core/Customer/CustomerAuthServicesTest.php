<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Customer;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAuthToken;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Customer\Message\PurgeUnverifiedCustomers;
use App\Core\Customer\Message\SendCustomerAuthMail;
use App\Core\Customer\MessageHandler\PurgeUnverifiedCustomersHandler;
use App\Core\Customer\MessageHandler\SendCustomerAuthMailHandler;
use App\Site\Account\AccountMails;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E12 PR-1: листи покупцю (усі види × мови), токени (строк, email, одноразовість), прибирання неперевірених. */
final class CustomerAuthServicesTest extends KernelTestCase
{
    public function testEveryAuthMailRendersInEveryLocale(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(SendCustomerAuthMailHandler::class);
        $kinds = [SendCustomerAuthMail::VERIFY, SendCustomerAuthMail::ACCOUNT_EXISTS, SendCustomerAuthMail::RESET, SendCustomerAuthMail::LOGIN_LINK, SendCustomerAuthMail::PASSWORD_CHANGED];
        foreach (['uk', 'ru', 'en'] as $locale) {
            foreach ($kinds as $kind) {
                $handler(new SendCustomerAuthMail($kind, 'buyer@example.test', 'Олена', $locale, 'https://shop.example/account/x?y=1&z=2', 15));
            }
        }
        $messages = self::getMailerMessages();
        self::assertCount(15, $messages);
        foreach ($messages as $i => $message) {
            self::assertInstanceOf(Email::class, $message);
            $text = (string) $message->getTextBody();
            $html = (string) $message->getHtmlBody();
            self::assertStringContainsString('https://shop.example/account/x?y=1&z=2', $text);
            self::assertStringContainsString('https://shop.example/account/x?y=1&amp;z=2', $html);
            // verify і account_exists може замовити будь-хто на чужу адресу: імені в них нема (рев'ю безпеки #45, п. 7)
            if (\in_array($kinds[$i % \count($kinds)], [SendCustomerAuthMail::VERIFY, SendCustomerAuthMail::ACCOUNT_EXISTS], true)) {
                self::assertStringNotContainsString('Олена', $text.$html);
            } else {
                self::assertStringContainsString('Олена', $text);
            }
            self::assertStringNotContainsString('email.account', $text.$html.$message->getSubject(), 'усі ключі перекладено');
        }
    }

    public function testTokenIsBoundToEmailExpiryAndSingleUse(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tokens = self::getContainer()->get(AuthTokens::class);
        $customer = $this->customer($em);
        $plain = $tokens->issue($customer, AuthTokenPurpose::ResetPassword);
        $other = $tokens->issue($customer, AuthTokenPurpose::ResetPassword);
        $em->flush();
        self::assertMatchesRegularExpression('~^'.AuthTokens::PATTERN.'$~', $plain);
        self::assertNull($em->getRepository(CustomerAuthToken::class)->findOneBy(['tokenHash' => $plain]), 'у БД лише хеш');
        self::assertNull($tokens->find($plain, AuthTokenPurpose::VerifyEmail), 'інше призначення');
        self::assertNotNull($tokens->find($plain, AuthTokenPurpose::ResetPassword));

        $applied = 0;
        self::assertSame($customer, $tokens->consume($plain, AuthTokenPurpose::ResetPassword, static function () use (&$applied): void { ++$applied; }));
        self::assertNull($tokens->consume($plain, AuthTokenPurpose::ResetPassword, static function () use (&$applied): void { ++$applied; }));
        self::assertNull($tokens->find($other, AuthTokenPurpose::ResetPassword), 'інші листи того ж призначення згасли');
        self::assertSame(1, $applied);

        // прострочений
        $late = $tokens->issue($customer, AuthTokenPurpose::ResetPassword);
        $em->flush();
        $row = $em->getRepository(CustomerAuthToken::class)->findOneBy(['tokenHash' => hash('sha256', $late)]);
        self::assertNotNull($row);
        (new ReflectionProperty(CustomerAuthToken::class, 'expiresAt'))->setValue($row, new DateTimeImmutable('-1 second'));
        $em->flush();
        self::assertNull($tokens->find($late, AuthTokenPurpose::ResetPassword));

        // email покупця змінився — старі листи не діють
        $fresh = $tokens->issue($customer, AuthTokenPurpose::ResetPassword);
        $em->flush();
        (new ReflectionProperty(Customer::class, 'email'))->setValue($customer, 'changed-'.bin2hex(random_bytes(3)).'@example.test');
        $em->flush();
        self::assertNull($tokens->find($fresh, AuthTokenPurpose::ResetPassword));
    }

    public function testPurgeRemovesOnlyOldUnverifiedCustomers(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = new Customer('old-'.bin2hex(random_bytes(3)).'@example.test', 'Старий', 'uk');
        $recent = new Customer('new-'.bin2hex(random_bytes(3)).'@example.test', 'Новий', 'uk');
        $verified = $this->customer($em);
        foreach ([$old, $verified] as $c) {
            (new ReflectionProperty(Customer::class, 'createdAt'))->setValue($c, new DateTimeImmutable('-8 days'));
        }
        $em->persist($old);
        $em->persist($recent);
        $em->flush();
        self::getContainer()->get(AuthTokens::class)->issue($old, AuthTokenPurpose::VerifyEmail);
        $em->flush();
        $ids = [$old->getId(), $recent->getId(), $verified->getId()];

        $deleted = self::getContainer()->get(PurgeUnverifiedCustomersHandler::class)(new PurgeUnverifiedCustomers());
        self::assertGreaterThanOrEqual(1, $deleted);
        $em->clear();
        self::assertNull($em->find(Customer::class, $ids[0]), 'неперевірений старший за 7 днів');
        self::assertNotNull($em->find(Customer::class, $ids[1]));
        self::assertNotNull($em->find(Customer::class, $ids[2]), 'підтверджений лишається');
    }

    /**
     * Рев'ю безпеки #45, п. 8 і повторне п. B: понад 10 листів на адресу за добу не йде, хоч би з яких IP. Добовий ліміт
     * списують лише надіслані листи: відхилені й «порожні» запити (allow без відправки) жертві листи не блокують.
     */
    public function testAuthMailsHaveDailyLimitPerAddress(): void
    {
        // межі тут свої (15-хвилинна не заважає), логіка AccountMails бойова; конфіг лімітера перевіряє lint:container
        self::bootKernel();
        $factory = static fn (string $id, int $limit, string $interval): RateLimiterFactory => new RateLimiterFactory(['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval], new InMemoryStorage());
        $mails = new AccountMails(
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(AuthAudit::class),
            $factory('ip', 1000, '15 minutes'),
            $factory('email', 1000, '15 minutes'),
            $factory('email_daily', 10, '1 day'),
            86400,
            3600,
        );
        $email = 'daily-'.bin2hex(random_bytes(4)).'@example.test';
        $customer = new Customer($email, 'Добова', 'uk');
        $request = static fn (int $i): Request => Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.'.$i]);
        for ($i = 1; $i <= 30; ++$i) {
            self::assertTrue($mails->allow($request($i), $email), 'запит без листа '.$i.' добовий ліміт не списує');
        }
        for ($i = 1; $i <= 10; ++$i) {
            self::assertTrue($mails->allow($request(100 + $i), $email), 'лист '.$i);
            $mails->reset($request(100 + $i), $customer, str_repeat('a', 43));
        }
        self::assertFalse($mails->allow($request(200), strtoupper($email)), 'одинадцятий лист за добу: новий IP і інший регістр не допомагають');
        self::assertTrue($mails->allow($request(201), 'other-'.$email), 'інша адреса');
    }

    private function customer(EntityManagerInterface $em): Customer
    {
        $customer = new Customer('tok-'.bin2hex(random_bytes(4)).'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail('$2y$04$hash');
        $em->persist($customer);
        $em->flush();

        return $customer;
    }
}

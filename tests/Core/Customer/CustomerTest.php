<?php

declare(strict_types=1);

namespace App\Tests\Core\Customer;

use App\Core\Customer\Entity\Customer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Покупець у сесії: лише id/email/crc хешу/authVersion/active; зміна будь-чого з них — розлогінення (isEqualTo). */
final class CustomerTest extends TestCase
{
    public function testSessionCopyComparesWithFreshEntity(): void
    {
        $fresh = $this->customer();
        $session = unserialize(serialize($fresh));
        self::assertInstanceOf(Customer::class, $session);
        self::assertNotSame($fresh->getPassword(), $session->getPassword(), 'у сесії не повний хеш');
        self::assertTrue($session->isEqualTo($fresh));

        $fresh->upgradePasswordHash('$2y$04$rehash');
        self::assertFalse($session->isEqualTo($fresh), 'інший хеш');

        $fresh = $this->customer();
        $session = unserialize(serialize($fresh));
        $fresh->logoutEverywhere();
        self::assertFalse($session->isEqualTo($fresh), 'authVersion');

        $fresh = $this->customer();
        $session = unserialize(serialize($fresh));
        $fresh->setActive(false);
        self::assertFalse($session->isEqualTo($fresh), 'вимкнено');
    }

    public function testPasswordChangeBumpsAuthVersionButRehashDoesNot(): void
    {
        $customer = $this->customer();
        $version = $customer->getAuthVersion();
        $customer->upgradePasswordHash('$2y$04$rehash');
        self::assertSame($version, $customer->getAuthVersion());
        $customer->changePasswordHash('$2y$04$other');
        self::assertSame($version + 1, $customer->getAuthVersion());
    }

    public function testEmailIsNormalizedAndValidated(): void
    {
        self::assertSame('olena@example.test', (new Customer('  Olena@Example.TEST ', 'Олена', 'uk'))->getEmail());
        $this->expectException(InvalidArgumentException::class);
        new Customer('not-an-email', 'Олена', 'uk');
    }

    /** Рев'ю безпеки #45, п. 2: реєстрація без імені й пароля, обидва задає власник скриньки при підтвердженні. */
    public function testRegistrationLeavesNameAndPasswordToVerification(): void
    {
        $customer = new Customer('new@example.test', '', 'uk');
        self::assertSame('', $customer->getName());
        self::assertNull($customer->getPassword());
        $customer->verifyEmail('$2y$04$owner', '  Власник ');
        self::assertSame('Власник', $customer->getName());
        self::assertTrue($customer->isEmailVerified());

        $this->expectException(InvalidArgumentException::class);
        (new Customer('nameless@example.test', '', 'uk'))->verifyEmail('$2y$04$hash');
    }

    private function customer(): Customer
    {
        $customer = new Customer('olena@example.test', 'Олена', 'uk');
        (new ReflectionProperty(Customer::class, 'id'))->setValue($customer, 7);
        $customer->verifyEmail('$2y$04$hash');

        return $customer;
    }
}

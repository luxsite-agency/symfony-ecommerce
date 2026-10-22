<?php

declare(strict_types=1);

namespace App\Tests\Site\Account;

use App\Core\Customer\Entity\Customer;
use App\Kernel;
use App\Site\Account\LoginTimingEqualizer;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Рев'ю безпеки #45, п. 4б і 5: фіктивний хеш при вході на відсутню адресу чи покупця без пароля (наявний з паролем
 * хешується в CheckCredentialsListener сам); trusted_hosts з точних імен хостів.
 */
final class LoginTimingEqualizerTest extends TestCase
{
    private int $hashed = 0;

    public function testUnknownEmailAndPasswordlessCustomerAreHashed(): void
    {
        $listener = $this->listener();
        $notFound = new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException());
        $listener($this->event($notFound, $this->passport(null)));
        self::assertSame(1, $this->hashed, 'відсутня адреса');

        $listener($this->event(new BadCredentialsException('The presented password is invalid.'), $this->passport(new Customer('a@example.test', '', 'uk'))));
        self::assertSame(2, $this->hashed, 'неперевірений без пароля');
    }

    public function testNoExtraHashWhenPasswordWasCheckedOrRequestThrottled(): void
    {
        $listener = $this->listener();
        $customer = new Customer('b@example.test', 'Б', 'uk');
        $customer->verifyEmail('$2y$04$hash');
        $listener($this->event(new BadCredentialsException('The presented password is invalid.'), $this->passport($customer)));
        // ліміт спроб і CSRF відмовляють до пошуку покупця: ні хешу, ні запиту в БД (повторне рев'ю #45, п. E)
        $listener($this->event(new TooManyLoginAttemptsAuthenticationException(), $this->passport(null, $this->failOnLoad())));
        $listener($this->event(new InvalidCsrfTokenException('Invalid CSRF token.'), $this->passport(null, $this->failOnLoad())));
        // вхід посиланням (пароля нема в жодній гілці) і чужий firewall
        $notFound = new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException());
        $listener($this->event($notFound, new SelfValidatingPassport(new UserBadge('x@example.test'))));
        $listener($this->event($notFound, $this->passport(null), 'admin'));
        self::assertSame(0, $this->hashed);
    }

    public function testTrustedHostPatternsAreExactAndEscaped(): void
    {
        $patterns = Kernel::trustedHostPatterns([' Shop.Example.com ', 'admin.example.com', '', null, 'shop.example.com']);
        self::assertSame(['^shop\.example\.com$', '^admin\.example\.com$'], $patterns);
        $matches = static fn (string $host): bool => [] !== array_filter($patterns, static fn (string $p): bool => 1 === preg_match('{'.$p.'}i', $host));
        self::assertTrue($matches('shop.example.com'));
        self::assertTrue($matches('ADMIN.example.com'));
        self::assertFalse($matches('shopxexample.com'), 'крапка не «будь-який символ»');
        self::assertFalse($matches('shop.example.com.evil.test'));
        self::assertFalse($matches('evil.test'));
    }

    private function listener(): LoginTimingEqualizer
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturnCallback(function (): string {
            ++$this->hashed;

            return '$2y$04$dummy';
        });
        $factory = $this->createStub(PasswordHasherFactoryInterface::class);
        $factory->method('getPasswordHasher')->willReturn($hasher);

        return new LoginTimingEqualizer($factory);
    }

    private function passport(?Customer $user, ?Closure $loader = null): Passport
    {
        return new Passport(new UserBadge('x@example.test', $loader ?? static fn (): Customer => $user ?? throw new UserNotFoundException()), new PasswordCredentials('presented password'));
    }

    /** Завантажувач покупця, який валить тест: слухач не повинен іти в БД. */
    private function failOnLoad(): Closure
    {
        return static fn (): never => throw new LogicException('Слухач не мав завантажувати покупця');
    }

    private function event(AuthenticationException $e, Passport $passport, string $firewall = 'site'): LoginFailureEvent
    {
        return new LoginFailureEvent($e, $this->createStub(AuthenticatorInterface::class), new Request(), null, $firewall, $passport);
    }
}

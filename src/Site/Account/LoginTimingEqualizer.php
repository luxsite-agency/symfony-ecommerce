<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Throwable;

/**
 * Однаковий час відповіді на вхід паролем (рев'ю безпеки #45, п. 4б). Для наявного покупця CheckCredentialsListener
 * рахує хеш пароля (bcrypt/argon, десятки мс), а для відсутньої адреси чи неперевіреного покупця без пароля невдача
 * настає без хешування, і за часом відповіді можна перебрати, які адреси зареєстровані. Тут у цих двох випадках
 * рахується фіктивний хеш тим самим хешером (`auto`, ті самі cost), тож обидві гілки тривають однаково.
 * Лише для BadCredentialsException (невірний пароль, відсутня адреса, покупець без пароля). Ліміт спроб
 * (TooManyLoginAttemptsAuthenticationException, LoginThrottlingListener) і CSRF (InvalidCsrfTokenException,
 * CsrfProtectionListener) відмовляють ще до пошуку покупця, тобто без запиту в БД і без хешування в жодній гілці; тут
 * вони пропускаються, щоб не додавати ні хешування, ні запиту (повторне рев'ю безпеки #45, п. E). Статус акаунта
 * (вимкнено, не підтверджено) перевіряється вже після пароля, хешування там уже було.
 */
final class LoginTimingEqualizer
{
    public function __construct(private readonly PasswordHasherFactoryInterface $hashers)
    {
    }

    #[AsEventListener]
    public function __invoke(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();
        if ('site' !== $event->getFirewallName() || null === $passport || !$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }
        $exception = $event->getException();
        if (!$exception instanceof BadCredentialsException || $exception->getPrevious() instanceof AccountStatusException) {
            return;
        }
        if (!self::isUserNotFound($exception) && !self::hasNoPassword($passport->getBadge(UserBadge::class))) {
            return;
        }
        $this->hashers->getPasswordHasher(Customer::class)->hash('login timing equalizer');
    }

    /** AuthenticatorManager загортає UserNotFoundException у BadCredentialsException (hide_user_not_found). */
    private static function isUserNotFound(?Throwable $e): bool
    {
        while (null !== $e) {
            if ($e instanceof UserNotFoundException) {
                return true;
            }
            $e = $e->getPrevious();
        }

        return false;
    }

    /**
     * Покупець знайдений, але пароля нема (неперевірений): CheckCredentialsListener відмовив без хешування. Покупця
     * UserBadge уже завантажив до CheckCredentialsListener, повторного запиту в БД нема.
     */
    private static function hasNoPassword(?object $badge): bool
    {
        if (!$badge instanceof UserBadge) {
            return false;
        }
        try {
            $user = $badge->getUser();
        } catch (AuthenticationException) {
            return false;
        }

        return $user instanceof PasswordAuthenticatedUserInterface && null === $user->getPassword();
    }
}

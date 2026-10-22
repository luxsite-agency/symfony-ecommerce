<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Входи й виходи покупців у журнал безпеки (firewall site); успішний вхід — last_login_at. */
final class LoginAudit
{
    public function __construct(private readonly AuthAudit $audit, private readonly EntityManagerInterface $em)
    {
    }

    #[AsEventListener]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $customer = $event->getUser();
        if ('site' !== $event->getFirewallName() || !$customer instanceof Customer) {
            return;
        }
        $customer->recordLogin();
        $this->em->flush();
        $this->audit->log('login', $customer->getEmail(), $customer->getId(), ['via' => self::via($event->getAuthenticator())]);
    }

    #[AsEventListener]
    public function onFailure(LoginFailureEvent $event): void
    {
        if ('site' !== $event->getFirewallName()) {
            return;
        }
        $email = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();
        $this->audit->log($event->getException() instanceof TooManyLoginAttemptsAuthenticationException ? 'login_throttled' : 'login_failed', $email, null, [
            'via' => self::via($event->getAuthenticator()),
            'reason' => $event->getException()->getMessageKey(),
        ]);
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $customer = $event->getToken()?->getUser();
        if ($customer instanceof Customer) {
            $this->audit->log('logout', $customer->getEmail(), $customer->getId());
        }
    }

    private static function via(AuthenticatorInterface $authenticator): string
    {
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }
        $authenticator = $authenticator::class;

        return match (true) {
            str_contains($authenticator, 'LoginLink') => 'login_link',
            str_contains($authenticator, 'RememberMe') => 'remember_me',
            str_contains($authenticator, 'FormLogin') => 'password',
            str_contains($authenticator, 'Google') => 'google',
            default => 'other',
        };
    }
}

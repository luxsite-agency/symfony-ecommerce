<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Security\Entity\User;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Входи й виходи адмінки (firewall admin) у журнал безпеки security_audit (E15 PR-1; до цього логувались лише покупці).
 * Як і для покупців: email — HMAC, IP — анонімізований; user_id — відкрито (обліковий запис співробітника).
 */
#[WithMonologChannel('security_audit')]
final class AdminLoginAudit
{
    public const string FIREWALL = 'admin';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requests,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    #[AsEventListener]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (self::FIREWALL !== $event->getFirewallName() || !$user instanceof User) {
            return;
        }
        $this->log('login', $user->getEmail(), $user->getId(), ['role' => $user->getRole()->value]);
    }

    #[AsEventListener]
    public function onFailure(LoginFailureEvent $event): void
    {
        if (self::FIREWALL !== $event->getFirewallName()) {
            return;
        }
        $email = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();
        $this->log($event->getException() instanceof TooManyLoginAttemptsAuthenticationException ? 'login_throttled' : 'login_failed', $email, null, [
            'reason' => $event->getException()->getMessageKey(),
        ]);
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->log('logout', $user->getEmail(), $user->getId());
        }
    }

    /** @param array<string, scalar|null> $context */
    private function log(string $event, ?string $email, ?int $userId, array $context = []): void
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();
        $this->logger->info('admin.'.$event, [
            'event' => $event,
            'user_id' => $userId,
            'email_hmac' => null === $email ? null : AuthAudit::emailHmac($email, $this->secret),
            'ip' => null === $ip ? null : IpUtils::anonymize($ip),
        ] + $context);
    }
}

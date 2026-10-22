<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Auth;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Журнал безпеки покупців (канал security_audit, на проді — окремий файл з ротацією 12 міс., monolog.yaml):
 * входи, листи й токени, ліміти, зміни пароля. Без паролів, токенів, cookie і відкритих email: email — HMAC
 * (порівнюваний між записами, але не читається), IP — анонімізований (IpUtils::anonymize).
 */
#[WithMonologChannel('security_audit')]
final class AuthAudit
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requests,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** @param array<string, scalar|null> $context */
    public function log(string $event, ?string $email = null, ?int $customerId = null, array $context = []): void
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();
        $this->logger->info('customer.'.$event, [
            'event' => $event,
            'customer_id' => $customerId,
            'email_hmac' => null === $email ? null : self::emailHmac($email, $this->secret),
            'ip' => null === $ip ? null : IpUtils::anonymize($ip),
        ] + $context);
    }

    public static function emailHmac(string $email, string $secret): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($email)), $secret), 0, 16);
    }
}

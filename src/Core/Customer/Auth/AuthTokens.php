<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Auth;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAuthToken;
use App\Core\Customer\Enum\AuthTokenPurpose;
use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Одноразові токени з листів покупця: 32 випадкові байти (base64url, 43 символи) у листі, sha256 — у БД.
 * Використання — у транзакції з блокуванням рядка: два одночасні кліки не застосують токен двічі; разом із ним гаснуть
 * інші відкриті токени того ж призначення (старі листи «скинути пароль» після скидання не діють).
 */
final class AuthTokens
{
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthTokenPolicy $policy,
        #[Autowire('%app.customer_verify_ttl%')] private readonly int $verifyTtl,
        #[Autowire('%app.customer_reset_ttl%')] private readonly int $resetTtl,
        #[Autowire('%app.customer_revert_ttl%')] private readonly int $revertTtl = 604800,
    ) {
    }

    /** Новий токен (persist без flush — разом із рештою змін виклику). Повертає токен для листа. $target — нова адреса для ChangeEmail. */
    public function issue(Customer $customer, AuthTokenPurpose $purpose, ?string $target = null): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        if (!$this->policy->issuesTokensFor($customer)) {
            return $plain; // політика (демо-акаунт публічного демо) забороняє робочі посилання з листів: токен не зберігається
        }
        $this->em->persist(new CustomerAuthToken($customer, $purpose, self::hash($plain), new DateTimeImmutable('+'.$this->ttl($purpose).' seconds'), $target));

        return $plain;
    }

    /** Строк дії, секунд: підтвердження, зміна email і експорт — 24 год, новий пароль — 1 год, повернення старої адреси — 7 днів. */
    public function ttl(AuthTokenPurpose $purpose): int
    {
        return match ($purpose) {
            AuthTokenPurpose::ResetPassword => $this->resetTtl,
            AuthTokenPurpose::RevertEmail => $this->revertTtl,
            default => $this->verifyTtl,
        };
    }

    /** sha256 токена — ім'я файлу експорту (сам токен на диску не лежить). */
    public static function hashOf(string $plain): string
    {
        return self::hash($plain);
    }

    /** Відкликати всі невикористані токени покупця (видалення акаунта, «вийти всюди»). */
    public function revokeAll(Customer $customer): void
    {
        foreach ($this->em->getRepository(CustomerAuthToken::class)->findBy(['customer' => $customer, 'consumedAt' => null]) as $open) {
            $open->consume();
        }
    }

    /** Дійсний токен без використання — для сторінки з кнопкою (GET не витрачає токен: сканери пошти). */
    public function find(string $plain, AuthTokenPurpose $purpose): ?CustomerAuthToken
    {
        $token = $this->em->getRepository(CustomerAuthToken::class)->findOneBy(['tokenHash' => self::hash($plain), 'purpose' => $purpose]);

        return null !== $token && $token->isUsable(new DateTimeImmutable()) ? $token : null;
    }

    /**
     * Використати токен: $apply(покупець) і позначка «використано» — одна транзакція з flush.
     *
     * @param Closure(Customer): void $apply
     */
    public function consume(string $plain, AuthTokenPurpose $purpose, Closure $apply): ?Customer
    {
        return $this->em->wrapInTransaction(function () use ($plain, $purpose, $apply): ?Customer {
            $token = $this->em->getRepository(CustomerAuthToken::class)->createQueryBuilder('t')
                ->andWhere('t.tokenHash = :hash')->andWhere('t.purpose = :purpose')
                ->setParameter('hash', self::hash($plain))->setParameter('purpose', $purpose->value)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if (!$token instanceof CustomerAuthToken) {
                return null;
            }
            $this->em->refresh($token);
            if (!$token->isUsable(new DateTimeImmutable())) {
                return null;
            }
            $customer = $token->getCustomer();
            $apply($customer);
            $token->consume();
            // через сутності, не DQL UPDATE: інакше identity map лишила б ці токени «відкритими» до кінця запиту
            foreach ($this->em->getRepository(CustomerAuthToken::class)->findBy(['customer' => $customer, 'purpose' => $purpose, 'consumedAt' => null]) as $open) {
                $open->consume();
            }

            return $customer;
        });
    }

    /** Прибирання: використані й прострочені понад добу тому. @return int видалено рядків */
    public function prune(): int
    {
        return (int) $this->em->createQuery('DELETE FROM '.CustomerAuthToken::class.' t WHERE t.expiresAt < :edge')
            ->setParameter('edge', new DateTimeImmutable('-1 day'))
            ->execute();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi;

use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Audit\AdminAuditLog;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\Security\Entity\User;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Видача, пошук і відкликання персональних API-токенів (E15 PR-2). Формат `shp_` + base64url(32 байти); у БД — sha256.
 * Кожна подія — у security_audit (без значення токена) і в журнал змін (entity_type api_token, без хешу).
 * Токени створює кожен собі (рішення власника 11.10, п. 4); адмін лише бачить і відкликає.
 */
#[WithMonologChannel('security_audit')]
final class AdminApiTokens
{
    public const string PREFIX = 'shp_';
    public const string AUDIT_TYPE = 'api_token';
    /** last_used_* оновлюється не частіше ніж раз на хвилину — не UPDATE на кожен запит. */
    private const int TOUCH_INTERVAL = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminAuditLog $audit,
        private readonly RoleHierarchyInterface $roles,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.admin_api_token_max_days%')] private readonly int $maxDays = 90,
    ) {
    }

    public function maxDays(): int
    {
        return max(1, $this->maxDays);
    }

    /**
     * Скоупи, які власник може дати своєму токену: лише ті, роль для яких у нього є.
     *
     * @return list<ApiScope>
     */
    public function allowedScopes(User $owner): array
    {
        $reachable = $this->roles->getReachableRoleNames($owner->getRoles());

        return array_values(array_filter(ApiScope::cases(), static fn (ApiScope $s): bool => \in_array($s->requiredRole()->value, $reachable, true)));
    }

    /**
     * @param list<ApiScope> $scopes
     *
     * @throws InvalidArgumentException назва, скоупи чи строк поза межами
     */
    public function issue(User $owner, string $name, array $scopes, ?int $days, User $by): IssuedToken
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 100) {
            throw new TranslatableInvalidArgumentException('Назва токена — від 1 до 100 символів', 'error.api_token.name_length');
        }
        if ([] === $scopes) {
            throw new TranslatableInvalidArgumentException('Виберіть хоча б один скоуп', 'error.api_token.scopes_required');
        }
        $allowed = $this->allowedScopes($owner);
        foreach ($scopes as $scope) {
            if (!\in_array($scope, $allowed, true)) {
                throw new TranslatableInvalidArgumentException(\sprintf('Скоуп %s недоступний для ролі користувача', $scope->value), 'error.api_token.scope_denied', ['scope' => $scope->value]);
            }
        }
        $days ??= $this->maxDays();
        if ($days < 1 || $days > $this->maxDays()) {
            throw new TranslatableInvalidArgumentException(\sprintf('Строк дії — від 1 до %d днів', $this->maxDays()), 'error.api_token.days_range', ['max' => (string) $this->maxDays()]);
        }

        $plain = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new DateTimeImmutable();
        $token = new AdminApiToken($owner, $name, $scopes, self::hash($plain), substr($plain, 0, AdminApiToken::PREFIX_LENGTH), $now->modify(\sprintf('+%d days', $days)));
        $this->em->persist($token);
        $this->em->flush();

        $context = $this->context($token, $by) + ['scopes' => $token->getScopeValues(), 'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM)];
        $this->logger->info('api_token.created', $context);
        $this->audit->write($this->actor($by), [[
            'action' => AuditAction::Create,
            'entity_type' => self::AUDIT_TYPE,
            'entity_id' => (int) $token->getId(),
            'entity_label' => $token->getName(),
            'locale' => null,
            'field' => null,
            'before' => null,
            'after' => ['name' => $token->getName(), 'prefix' => $token->getPrefix(), 'owner_id' => $owner->getId(), 'scopes' => $token->getScopeValues(), 'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM)],
        ]]);

        return new IssuedToken($token, $plain);
    }

    /** Ідемпотентно: уже відкликаний — без запису. */
    public function revoke(AdminApiToken $token, User $by): void
    {
        if ($token->isRevoked()) {
            return;
        }
        $token->revoke($by);
        $this->em->flush();
        $this->logger->info('api_token.revoked', $this->context($token, $by));
        $this->audit->write($this->actor($by), [[
            'action' => AuditAction::Update,
            'entity_type' => self::AUDIT_TYPE,
            'entity_id' => (int) $token->getId(),
            'entity_label' => $token->getName(),
            'locale' => null,
            'field' => 'revokedAt',
            'before' => null,
            'after' => $token->getRevokedAt()?->format(\DATE_ATOM),
        ]]);
    }

    /** Деактивація користувача: усі його чинні токени — відкликані. @return int скільки відкликано */
    public function revokeAllOf(User $owner, User $by): int
    {
        $n = 0;
        foreach ($this->forUser($owner) as $token) {
            if (!$token->isRevoked()) {
                $this->revoke($token, $by);
                ++$n;
            }
        }

        return $n;
    }

    /** @return list<AdminApiToken> */
    public function forUser(User $owner): array
    {
        return $this->em->getRepository(AdminApiToken::class)->findBy(['user' => $owner], ['createdAt' => 'DESC']);
    }

    /** Пошук за значенням: формат не наш → null без запиту до БД. Чинність (відкликано, строк) перевіряє автентифікатор. */
    public function findByPlain(#[SensitiveParameter] string $plain): ?AdminApiToken
    {
        if (!self::looksLikeToken($plain)) {
            return null;
        }

        return $this->em->getRepository(AdminApiToken::class)->findOneBy(['tokenHash' => self::hash($plain)]);
    }

    public static function looksLikeToken(string $value): bool
    {
        return 1 === preg_match('/^shp_[A-Za-z0-9_-]{43}$/', $value);
    }

    public static function hash(#[SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Слід використання (раз на хвилину, DBAL-ом — без flush у середині автентифікації). Перша поява іншої (анонімізованої)
     * адреси — подія в security_audit.
     */
    public function touch(AdminApiToken $token, ?string $ip, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $anonymized = null === $ip || '' === $ip ? null : IpUtils::anonymize($ip);
        $last = $token->getLastUsedAt();
        $newIp = null !== $anonymized && $anonymized !== $token->getLastUsedIp();
        if (null !== $last && !$newIp && ($now->getTimestamp() - $last->getTimestamp()) < self::TOUCH_INTERVAL) {
            return;
        }
        if ($newIp && null !== $token->getLastUsedIp()) {
            $this->logger->info('api_token.new_ip', ['token_id' => $token->getId(), 'prefix' => $token->getPrefix(), 'user_id' => $token->getUser()->getId(), 'ip' => $anonymized, 'previous_ip' => $token->getLastUsedIp()]);
        }
        $token->touch($now, $anonymized);
        $this->em->getConnection()->update('admin_api_token', ['last_used_at' => $now->format('Y-m-d H:i:s'), 'last_used_ip' => $anonymized], ['id' => $token->getId()]);
    }

    /** @return array<string, mixed> */
    private function context(AdminApiToken $token, User $by): array
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();

        return [
            'token_id' => $token->getId(),
            'prefix' => $token->getPrefix(),
            'name' => $token->getName(),
            'owner_id' => $token->getUser()->getId(),
            'user_id' => $by->getId(),
            'actor' => $by->getDisplayName(),
            'ip' => null === $ip ? null : IpUtils::anonymize($ip),
        ];
    }

    private function actor(User $by): AuditActor
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();

        return AuditActor::user($by, AuditSource::AdminUi, null === $ip ? null : IpUtils::anonymize($ip));
    }
}

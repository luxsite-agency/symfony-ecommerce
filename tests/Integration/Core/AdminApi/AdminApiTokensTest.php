<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Видача/відкликання токенів (E15 PR-2): формат, хеш у БД, межі, аудит у security_audit і admin_audit_entry без значення токена. */
final class AdminApiTokensTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private AdminApiTokens $tokens;
    private TestHandler $security;
    private User $manager;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->tokens = self::getContainer()->get(AdminApiTokens::class);
        $logger = self::getContainer()->get('monolog.logger.security_audit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->security = new TestHandler());
        $this->manager = new User('olena@example.test', 'Олена', UserRole::Manager);
        $this->admin = new User('admin@example.test', 'Адмін', UserRole::Admin);
        $this->em->persist($this->manager);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    public function testIssueStoresHashOnlyAndJournals(): void
    {
        $issued = $this->tokens->issue($this->manager, ' Claude Code ', [ApiScope::CatalogRead, ApiScope::ContentWrite], null, $this->manager);
        $plain = $issued->plain;
        self::assertMatchesRegularExpression('/^shp_[A-Za-z0-9_-]{43}$/', $plain);
        self::assertTrue(AdminApiTokens::looksLikeToken($plain));
        $token = $issued->token;
        self::assertSame('Claude Code', $token->getName());
        self::assertSame(substr($plain, 0, 12), $token->getPrefix());
        self::assertSame(hash('sha256', $plain), $token->getTokenHash());
        self::assertSame(['catalog:read', 'content:write'], $token->getScopeValues());
        self::assertEqualsWithDelta(90 * 86400, $token->getExpiresAt()->getTimestamp() - time(), 3700, 'типовий строк — app.admin_api_token_max_days (±година переходу на зимовий час)');
        self::assertSame('active', $token->getStatus());

        $row = $this->db->fetchAssociative('SELECT * FROM admin_api_token WHERE id = ?', [$token->getId()]);
        self::assertNotFalse($row);
        self::assertStringNotContainsString($plain, (string) json_encode($row), 'значення не зберігається');
        self::assertSame($token->getTokenHash(), $row['token_hash']);

        self::assertSame($token, $this->tokens->findByPlain($plain));
        self::assertNull($this->tokens->findByPlain('shp_'.str_repeat('x', 43)));
        self::assertNull($this->tokens->findByPlain('not-a-token'));

        // security_audit: подія без значення
        self::assertTrue($this->security->hasInfoThatContains('api_token.created'));
        $record = $this->security->getRecords()[0];
        self::assertSame($token->getId(), $record->context['token_id']);
        self::assertSame($token->getPrefix(), $record->context['prefix']);
        self::assertStringNotContainsString($plain, (string) json_encode($record->context));
        // журнал змін: create без хешу
        $audit = $this->db->fetchAllAssociative("SELECT * FROM admin_audit_entry WHERE entity_type = 'api_token' AND entity_id = ?", [$token->getId()]);
        self::assertCount(1, $audit);
        self::assertSame(['create', 'admin_ui', $this->manager->getId(), 'Claude Code'], [$audit[0]['action'], $audit[0]['source'], (int) $audit[0]['user_id'], $audit[0]['entity_label']]);
        $after = json_decode($audit[0]['new_value'], true);
        self::assertSame(['catalog:read', 'content:write'], $after['scopes']);
        self::assertArrayNotHasKey('token_hash', $after);
        self::assertStringNotContainsString($plain, $audit[0]['new_value']);
    }

    public function testLimitsAreEnforced(): void
    {
        foreach ([
            ['', [ApiScope::CatalogRead], null],
            [str_repeat('x', 101), [ApiScope::CatalogRead], null],
            ['t', [], null],
            ['t', [ApiScope::CatalogRead], 0],
            ['t', [ApiScope::CatalogRead], 91],
        ] as [$name, $scopes, $days]) {
            try {
                $this->tokens->issue($this->manager, $name, $scopes, $days, $this->manager);
                self::fail('очікували InvalidArgumentException');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_api_token'));
        self::assertSame(ApiScope::cases(), $this->tokens->allowedScopes($this->manager));
        self::assertSame(ApiScope::cases(), $this->tokens->allowedScopes($this->admin));
        self::assertSame(90, $this->tokens->maxDays());
        $short = $this->tokens->issue($this->manager, 'день', [ApiScope::CatalogRead], 1, $this->manager)->token;
        self::assertEqualsWithDelta(86400, $short->getExpiresAt()->getTimestamp() - time(), 3700);
    }

    public function testRevokeIsIdempotentAndRevokeAllOnDeactivation(): void
    {
        $a = $this->tokens->issue($this->manager, 'a', [ApiScope::CatalogRead], null, $this->manager)->token;
        $b = $this->tokens->issue($this->manager, 'b', [ApiScope::PricesWrite], null, $this->manager)->token;
        $this->security->clear();

        $this->tokens->revoke($a, $this->admin);
        self::assertTrue($a->isRevoked());
        self::assertSame($this->admin->getId(), $a->getRevokedById());
        $this->tokens->revoke($a, $this->admin);
        self::assertCount(1, $this->security->getRecords(), 'повторне відкликання — без подій');
        self::assertSame('api_token.revoked', $this->security->getRecords()[0]->message);
        $audit = $this->db->fetchAllAssociative("SELECT * FROM admin_audit_entry WHERE entity_type = 'api_token' AND entity_id = ? AND action = 'update'", [$a->getId()]);
        self::assertCount(1, $audit);
        self::assertSame('revokedAt', $audit[0]['field']);
        self::assertSame($this->admin->getId(), (int) $audit[0]['user_id']);

        self::assertSame(1, $this->tokens->revokeAllOf($this->manager, $this->admin));
        self::assertTrue($b->isRevoked());
        self::assertSame(0, $this->tokens->revokeAllOf($this->manager, $this->admin));
        self::assertSame('revoked', $b->getStatus());
    }

    public function testTouchIsThrottledAndLogsNewIp(): void
    {
        $token = $this->tokens->issue($this->manager, 't', [ApiScope::CatalogRead], null, $this->manager)->token;
        $this->security->clear();
        $t0 = new DateTimeImmutable('2026-10-12 10:00:00');

        $this->tokens->touch($token, '203.0.113.77', $t0);
        self::assertSame('203.0.113.0', $token->getLastUsedIp(), 'IP анонімізований');
        self::assertSame('2026-10-12 10:00:00', $this->db->fetchOne('SELECT last_used_at FROM admin_api_token WHERE id = ?', [$token->getId()]));
        self::assertFalse($this->security->hasRecords(Level::Info), 'перша адреса — не «нова»');

        $this->tokens->touch($token, '203.0.113.78', $t0->modify('+10 seconds'));
        self::assertSame('2026-10-12 10:00:00', $this->db->fetchOne('SELECT last_used_at FROM admin_api_token WHERE id = ?', [$token->getId()]), 'той самий /24 у межах хвилини — без UPDATE');

        $this->tokens->touch($token, '198.51.100.5', $t0->modify('+20 seconds'));
        self::assertSame('198.51.100.0', $token->getLastUsedIp());
        self::assertTrue($this->security->hasInfoThatContains('api_token.new_ip'), 'інша адреса — подія і UPDATE попри хвилину');
        self::assertSame('2026-10-12 10:00:20', $this->db->fetchOne('SELECT last_used_at FROM admin_api_token WHERE id = ?', [$token->getId()]));

        $this->tokens->touch($token, '198.51.100.5', $t0->modify('+2 minutes'));
        self::assertSame('2026-10-12 10:02:00', $this->db->fetchOne('SELECT last_used_at FROM admin_api_token WHERE id = ?', [$token->getId()]));
    }
}

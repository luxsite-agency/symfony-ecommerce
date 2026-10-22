<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\AdminApi;

use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Core\AdminApi\Auth\ScopeVoter;
use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/** scope:* — лише з API-токеном, лише зі скоупом у токені, лише з роллю власника (токен не може більше за людину). */
final class ScopeVoterTest extends TestCase
{
    private ScopeVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ScopeVoter(new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_MANAGER']]));
    }

    public function testSessionWithoutApiTokenIsDenied(): void
    {
        $token = new PostAuthenticationToken(new User('a@example.test', 'A', UserRole::Admin), 'admin', ['ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, ['scope:catalog:read']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, null, ['ROLE_ADMIN']), 'не свої атрибути — утримується');
    }

    public function testScopeMustBeInTokenAndRoleMustAllowIt(): void
    {
        $user = new User('m@example.test', 'M', UserRole::Manager);
        $api = new AdminApiToken($user, 't', [ApiScope::CatalogRead], str_repeat('a', 64), 'shp_12345678', new DateTimeImmutable('+1 day'));
        $token = new PostAuthenticationToken($user, 'admin_api', ['ROLE_MANAGER']);
        $token->setAttribute(ApiTokenContext::ATTRIBUTE, $api);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, ['scope:catalog:read']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, ['scope:prices:write']), 'скоупу нема в токені');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, ['scope:orders:read']), 'невідомий скоуп');

        // скоуп у токені є, але ролі в людини вже нема (роль змінили після видачі)
        $demoted = new PostAuthenticationToken($user, 'admin_api', ['ROLE_CUSTOMER']);
        $demoted->setAttribute(ApiTokenContext::ATTRIBUTE, $api);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($demoted, null, ['scope:catalog:read']));
    }
}

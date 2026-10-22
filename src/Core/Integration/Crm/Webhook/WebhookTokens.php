<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm\Webhook;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Токени вебхука CRM (консиліум безпеки 10.10, п. 8; рішення власника: KeyCRM шле лише URL + POST, без власних
 * заголовків і підпису, тож `?token=` лишається основним способом). Захист: hash_equals, токен у query не пишеться
 * в access-лог nginx (docker/nginx/*.conf, location /webhooks/), ліміт з IP (CrmWebhookController), ротація
 * (crm:webhook:rotate-token) з 24-годинним перехідним періодом. `Authorization: Bearer` приймається додатково.
 *
 * Чинний токен: останній у БД без expires_at, а до першої ротації — env CRM_WEBHOOK_TOKEN. Після ротації env-токен
 * (як і будь-який попередній) живе ще GRACE і далі не приймається.
 */
final class WebhookTokens
{
    public const string GRACE = 'PT24H';
    public const int BYTES = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(CRM_WEBHOOK_TOKEN)%')] private readonly string $envToken,
    ) {
    }

    /** Токен із запиту: заголовок Authorization: Bearer, інакше ?token=. */
    public static function presented(Request $request): ?string
    {
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization', ''), $m)) {
            return $m[1];
        }

        return $request->query->has('token') ? (string) $request->query->get('token', '') : null;
    }

    public function isValid(?string $presented): bool
    {
        if (null === $presented || '' === $presented) {
            return false;
        }
        $now = new DateTimeImmutable();
        $rows = $this->em->getRepository(CrmWebhookToken::class)->findAll();
        if ([] === $rows) {
            return '' !== $this->envToken && hash_equals($this->envToken, $presented);
        }
        $hash = self::hash($presented);
        $valid = false;
        foreach ($rows as $row) {
            // без раннього виходу: час порівняння не залежить від того, який саме рядок збігся
            $valid = ($row->isUsable($now) && hash_equals($row->getTokenHash(), $hash)) || $valid;
        }

        return $valid;
    }

    /**
     * Ротація: новий токен чинний одразу, попередні (і env-токен при першій ротації) діють ще GRACE. Повертає відкритий
     * токен, який ніде більше не зберігається.
     */
    public function rotate(): string
    {
        $now = new DateTimeImmutable();
        $expires = $now->add(new DateInterval(self::GRACE));
        $repository = $this->em->getRepository(CrmWebhookToken::class);
        $rows = $repository->findAll();
        if ([] === $rows && '' !== $this->envToken) {
            $this->em->persist(new CrmWebhookToken(self::hash($this->envToken), $expires));
        }
        foreach ($rows as $row) {
            if (!$row->isUsable($now)) {
                $this->em->remove($row);
            } elseif ($row->isCurrent()) {
                $row->retire($expires);
            }
        }
        $token = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
        $this->em->persist(new CrmWebhookToken(self::hash($token)));
        $this->em->flush();

        return $token;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

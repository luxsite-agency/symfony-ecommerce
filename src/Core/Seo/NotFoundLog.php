<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

use App\Core\Customer\Http\AccountPath;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Журнал 404 сайту (seo_not_found), агрегований за шляхом. Відомий шлях — один UPDATE за унікальним індексом (без
 * спалювання auto_increment), новий — INSERT (гонка двох перших звернень — ON DUPLICATE KEY). Referer — лише схема,
 * хост і шлях (query може нести персональні дані). Обсяг: рядки, старші за $retentionDays, і понад $limit (найдавніші
 * за last_hit_at) прибираються — приблизно раз на PRUNE_EVERY нових шляхів, щоночі (Schedule) і командою
 * seo:not-found:prune. Сканування ботів (*.php, wp-*, /.env, /.git…) у журнал не пишуться — редирект для них не потрібен,
 * а місце в ліміті вони з'їдали б (рев'ю #37). Час — з ClockInterface (не NOW() MySQL), частота вибіркового прибирання —
 * $pruneEvery (0 — вимкнено): так тест журналу детермінований.
 */
final class NotFoundLog
{
    private const int PRUNE_EVERY = 100;
    private const int REFERER_LENGTH = 500;
    private const string PROBE = '~(?:\.(?:php\d?|aspx?|jsp|cgi|env|ini|sql|bak|old|swp|ya?ml|log|git)(?:/|$)|/wp-|/\.|/cgi-bin/|/phpmyadmin|/xmlrpc)~i';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.seo_not_found_limit%')] private readonly int $limit,
        #[Autowire('%app.seo_not_found_days%')] private readonly int $retentionDays,
        private readonly ?ClockInterface $clock = null,
        private readonly int $pruneEvery = self::PRUNE_EVERY,
    ) {
    }

    public static function isProbe(string $path): bool
    {
        return 1 === preg_match(self::PROBE, $path);
    }

    public function record(string $path, ?string $referer): void
    {
        // кабінет: обрізаний токен з листа в шляху 404 — майже весь токен у журналі (повторне рев'ю безпеки #45, п. A)
        if (self::isProbe($path) || AccountPath::matches($path)) {
            return;
        }
        $referer = self::cleanReferer($referer);
        $now = $this->now()->format('Y-m-d H:i:s');
        if (0 < $this->db->executeStatement('UPDATE seo_not_found SET hits = hits + 1, last_hit_at = ?, referer = COALESCE(?, referer) WHERE path = ?', [$now, $referer, $path])) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO seo_not_found (path, hits, first_seen_at, last_hit_at, referer) VALUES (?, 1, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE hits = seo_not_found.hits + 1, last_hit_at = new.last_hit_at, referer = COALESCE(new.referer, seo_not_found.referer)',
            [$path, $now, $now, $referer],
        );
        if (0 < $this->pruneEvery && 1 === random_int(1, $this->pruneEvery)) {
            $this->prune();
        }
    }

    /** @return int видалених рядків */
    public function prune(): int
    {
        $cutoff = $this->now()->modify(\sprintf('-%d days', max(1, $this->retentionDays)))->format('Y-m-d H:i:s');
        $deleted = (int) $this->db->executeStatement('DELETE FROM seo_not_found WHERE last_hit_at < ?', [$cutoff]);
        $edge = $this->db->fetchAssociative('SELECT id, last_hit_at FROM seo_not_found ORDER BY last_hit_at DESC, id DESC LIMIT 1 OFFSET '.max(0, $this->limit - 1));
        if (false !== $edge) {
            $deleted += (int) $this->db->executeStatement('DELETE FROM seo_not_found WHERE last_hit_at < ? OR (last_hit_at = ? AND id < ?)', [$edge['last_hit_at'], $edge['last_hit_at'], $edge['id']]);
        }

        return $deleted;
    }

    public function clear(): int
    {
        return (int) $this->db->executeStatement('DELETE FROM seo_not_found');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private static function cleanReferer(?string $referer): ?string
    {
        if (null === $referer || !RedirectPath::isAbsoluteUrl($referer)) {
            return null;
        }
        $parts = parse_url($referer);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        // перехід зі сторінки кабінету: шлях може нести токен листа (/account/password/{token}) — лише origin
        $path = $parts['path'] ?? '/';
        $clean = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').(AccountPath::matches($path) ? '/' : $path);

        return mb_substr($clean, 0, self::REFERER_LENGTH);
    }
}

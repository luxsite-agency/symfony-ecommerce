<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Entity;

use App\Core\Seo\RedirectPath;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал 404 сайту (епік E3), агрегований за нормалізованим шляхом: скільки разів, коли вперше й востаннє, останній
 * referer (без query). Без IP, user agent і параметрів запиту — персональних даних тут нема. Пише NotFoundLog (DBAL),
 * обсяг обмежений (app.seo_not_found_limit / _days). Сутність — для адмінки.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'seo_not_found')]
#[ORM\UniqueConstraint(name: 'uniq_seo_not_found_path', columns: ['path'])]
#[ORM\Index(name: 'idx_seo_not_found_last_hit', columns: ['last_hit_at'])]
class NotFoundEntry
{
    /** BIGINT: новий шлях на кожне звернення бота з унікальним URL — auto_increment росте швидше за обсяг таблиці. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private int|string|null $id = null;

    #[ORM\Column(length: RedirectPath::MAX_LENGTH, options: ['collation' => 'utf8mb4_bin'])]
    private string $path;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 1])]
    private int $hits = 1;

    #[ORM\Column]
    private DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private DateTimeImmutable $lastHitAt;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $referer = null;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->firstSeenAt = $this->lastHitAt = new DateTimeImmutable();
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastHitAt(): DateTimeImmutable
    {
        return $this->lastHitAt;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }
}

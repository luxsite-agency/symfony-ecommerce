<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Звідки взято файл медіатеки (імпорт зображень за URL, MediaImporter): sha256(URL) → Media. Повторний імпорт того самого
 * URL файл не качає; кілька URL можуть вести на один Media (однаковий вміст, дедуплікація за хешем).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_media_source')]
class MediaSource
{
    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $urlHash;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $url, Media $media)
    {
        $this->urlHash = self::hash($url);
        $this->url = $url;
        $this->media = $media;
        $this->createdAt = new DateTimeImmutable();
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }
}

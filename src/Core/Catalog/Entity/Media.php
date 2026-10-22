<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Файл медіатеки: лише метадані, шлях — ключ у сховищі відносно public/media. Завантаження й прев'ю (Vich/Liip) — окремо.
 * Один файл може належати кільком товарам через ProductMedia.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_media')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_media_path', columns: ['path'])]
#[ORM\Index(name: 'idx_catalog_media_content_hash', columns: ['content_hash'])]
#[UniqueEntity(fields: ['path'], message: 'catalog.media.path.unique')]
#[Vich\Uploadable]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** sha256 вмісту файлу — дедуплікація при завантаженні з URL (MediaImporter); null — завантажено вручну до цього поля. */
    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $contentHash = null;

    /**
     * Лише растрові зображення (консиліум безпеки 10.10, п. 7): SVG і PDF можуть нести скрипти, а лежать на origin сайту
     * (/media/) від імені будь-якого менеджера. Перевіряється і MIME за вмістом, і розширення: файл x.html з <svg><script>
     * finfo бачить як image/svg+xml, а nginx віддав би за розширенням як text/html. Старі svg/pdf у /media/ nginx віддає
     * як attachment у CSP sandbox (docker/nginx/*.conf).
     *
     * @var list<string>
     */
    public const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    /** @var list<string> розширення, MIME яких (за Mime-компонентом) мусить збігатися з вмістом */
    public const array ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /**
     * Завантажений файл (лише в пам'яті): Vich на prePersist кладе його в public/media і через сеттери заповнює
     * path/mimeType/sizeBytes. Замінити файл існуючого запису не можна — завантажується новий Media.
     */
    #[Vich\UploadableField(mapping: 'catalog_media', fileNameProperty: 'path', size: 'sizeBytes', mimeType: 'mimeType')]
    #[Assert\When(expression: 'this.getId() === null', constraints: [new Assert\NotNull(message: 'catalog.media.file.not_null')])]
    #[Assert\File(maxSize: '20M', mimeTypes: self::ALLOWED_MIME_TYPES, mimeTypesMessage: 'catalog.media.file.mime_types', extensions: self::ALLOWED_EXTENSIONS, extensionsMessage: 'catalog.media.file.extensions')]
    private ?File $file = null;

    public function __construct(string $path, string $mimeType, int $sizeBytes)
    {
        $this->setPath($path);
        $this->setMimeType($mimeType);
        $this->setSizeBytes($sizeBytes);
        $this->createdAt = new DateTimeImmutable();
    }

    /** Заповнюється формою; розміри зображення знімаються одразу, бо Vich їх сюди не пише. */
    public function setFile(?File $file): void
    {
        $this->file = $file;
        if (null !== $file && str_starts_with((string) $file->getMimeType(), 'image/')) {
            $size = @getimagesize($file->getPathname());
            $this->setDimensions(false === $size ? null : $size[0], false === $size ? null : $size[1]);
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    /** @internal для Vich (fileNameProperty) і конструктора */
    public function setPath(string $path): void
    {
        $path = ltrim(trim($path), '/');
        if ('' === $path || \strlen($path) > 255 || str_contains($path, '..')) {
            throw new TranslatableInvalidArgumentException(\sprintf('Недопустимий шлях медіа: "%s"', $path), 'error.media.invalid_path', ['path' => $path]);
        }
        $this->path = $path;
    }

    /** @internal для Vich */
    public function setMimeType(string $mimeType): void
    {
        if (!preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $mimeType)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не MIME-тип: "%s"', $mimeType), 'error.media.invalid_mime', ['mime' => $mimeType]);
        }
        $this->mimeType = $mimeType;
    }

    /** @internal для Vich */
    public function setSizeBytes(int $sizeBytes): void
    {
        if ($sizeBytes < 0) {
            throw new TranslatableInvalidArgumentException('Розмір файлу не може бути від\'ємним', 'error.media.negative_size');
        }
        $this->sizeBytes = $sizeBytes;
    }

    public function __toString(): string
    {
        return $this->alt ?? $this->path;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setDimensions(?int $width, ?int $height): void
    {
        if ((null === $width) !== (null === $height) || $width < 0 || $height < 0) {
            throw new TranslatableInvalidArgumentException('Розміри зображення задаються парою невід\'ємних чисел', 'error.media.invalid_dimensions');
        }
        $this->width = $width;
        $this->height = $height;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): void
    {
        $this->alt = $alt;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): void
    {
        $this->contentHash = $contentHash;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

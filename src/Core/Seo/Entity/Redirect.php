<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Entity;

use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\Enum\RedirectKind;
use App\Core\Seo\RedirectPath;
use App\Core\Seo\Validator\ValidRedirect;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Правило менеджера редиректів (епік E3): адреса сайту, якої вже нема, → 301/302 на нову або 404/410. Спрацьовує лише
 * на 404 сайту (Site\Redirect\NotFoundRedirectListener) — живі сторінки правило не перекриває. Джерело нормалізоване
 * (RedirectPath), унікальне, у бінарному порівнянні (/Odyah і /odyah — різні рядки, але джерело завжди в нижньому
 * регістрі). Ланцюги не зберігаються: ціль 301/302 ніколи не є джерелом іншого 301/302 (RedirectManager).
 */
#[ORM\Entity]
#[ORM\Table(name: 'seo_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_seo_redirect_source', columns: ['source'])]
#[ORM\Index(name: 'idx_seo_redirect_target_path', columns: ['target_path'])]
#[UniqueEntity(fields: ['source'], message: 'seo.redirect.source.unique')]
#[ValidRedirect]
class Redirect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: RedirectPath::MAX_LENGTH, options: ['collation' => 'utf8mb4_bin'])]
    #[Assert\NotBlank(message: 'seo.redirect.source.not_blank')]
    private string $source = '';

    #[ORM\Column(length: RedirectPath::MAX_TARGET_LENGTH, nullable: true)]
    private ?string $target = null;

    /** Ціль-шлях без query (RedirectPath::targetPath) — індекс для злиття ланцюгів «X→A, тепер A→B ⇒ X→B». */
    #[ORM\Column(length: RedirectPath::MAX_LENGTH, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $targetPath = null;

    #[ORM\Column(type: Types::SMALLINT, enumType: RedirectCode::class)]
    private RedirectCode $code = RedirectCode::Permanent;

    #[ORM\Column(length: 8, enumType: RedirectKind::class)]
    private RedirectKind $kind = RedirectKind::Manual;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $hits = 0;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastHitAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** Сирі значення з форми (до нормалізації) — для повідомлень валідатора. */
    private ?string $rawSource = null;
    private ?string $rawTarget = null;

    public function __construct(string $source = '', RedirectCode $code = RedirectCode::Permanent, ?string $target = null)
    {
        $this->createdAt = new DateTimeImmutable();
        $this->setSource($source);
        $this->setCode($code);
        $this->setTarget($target);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->rawSource = $source;
        $this->source = RedirectPath::normalize((string) $source) ?? '';

        return $this;
    }

    public function getRawSource(): ?string
    {
        return $this->rawSource;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    /** Для 404/410 ціль не зберігається. Невалідна ціль → null (валідатор повідомить за rawTarget). */
    public function setTarget(?string $target): self
    {
        $this->rawTarget = $target;
        $this->target = null === $target || '' === trim($target) ? null : RedirectPath::normalizeTarget($target);
        $this->targetPath = null === $this->target ? null : RedirectPath::targetPath($this->target);

        return $this;
    }

    public function getRawTarget(): ?string
    {
        return $this->rawTarget;
    }

    public function getCode(): RedirectCode
    {
        return $this->code;
    }

    public function setCode(RedirectCode $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getKind(): RedirectKind
    {
        return $this->kind;
    }

    public function setKind(RedirectKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getLastHitAt(): ?DateTimeImmutable
    {
        return $this->lastHitAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Ціль після злиття ланцюга (RedirectManager::resolve), без сирого значення форми. */
    public function retarget(string $target): void
    {
        $this->target = $target;
        $this->targetPath = RedirectPath::targetPath($target);
    }

    /** 404/410 ціль не мають. */
    public function dropTarget(): void
    {
        $this->target = $this->targetPath = null;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Settings\Entity;

use App\Core\Settings\SettingKeys;
use App\Core\Shared\Crypto\SecretBox;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Налаштування магазину «ключ → значення» (як у внутрішнього проєкту LuxSite): відомі ключі й їхні типи — у SettingKeys, значення
 * зберігається рядком як є (без екранування — HTML-коди публікуються сирими, доступ лише ROLE_ADMIN).
 */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
#[ORM\UniqueConstraint(name: 'uniq_setting_code', columns: ['code'])]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $code, ?string $value = null)
    {
        $this->code = SettingKeys::assertKnown($code);
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    /** Значення як є: HTML не екранується і не нормалізується; порожній рядок → null. */
    public function setValue(?string $value): void
    {
        $this->value = null === $value || '' === trim($value) ? null : $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Значення для показу: секрет — лише маска (шаблони полів EasyAdmin кладуть сире field.value у title). */
    public function getValueText(): ?string
    {
        return SettingKeys::isSecret($this->code) ? SecretBox::mask($this->value) : $this->value;
    }

    public function getLabel(): string
    {
        return SettingKeys::label($this->code);
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}

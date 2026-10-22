<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Entity;

use App\Core\Shared\Text\MachineCode;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Зовнішній ідентифікатор сутності в іншій системі (ADR Rev 3): ядро не знає про конкретну CRM, жодних keycrm-полів у сутностях.
 * Приклад: (system=keycrm, entity_type=product_variant, entity_id=42, external_id=offer 1017).
 *
 * Унікальність — у межах (system, entity_type), а не (system, external_id), як у Rev 3: у KeyCRM product_id, offer_id і
 * order_id — окремі послідовності й можуть збігатися. Див. питання в PR.
 */
#[ORM\Entity]
#[ORM\Table(name: 'integration_external_ref')]
#[ORM\UniqueConstraint(name: 'uniq_integration_external_ref_external', columns: ['system_code', 'entity_type', 'external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_integration_external_ref_entity', columns: ['system_code', 'entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_integration_external_ref_entity', columns: ['entity_type', 'entity_id'])]
class ExternalRef
{
    public const string TYPE_PRODUCT = 'product';
    public const string TYPE_PRODUCT_VARIANT = 'product_variant';
    public const string TYPE_ORDER = 'order';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SYSTEM — зарезервоване слово MySQL 8, тому колонка system_code. */
    #[ORM\Column(name: 'system_code', length: 32)]
    private string $system;

    #[ORM\Column(length: 32)]
    private string $entityType;

    #[ORM\Column]
    private int $entityId;

    #[ORM\Column(length: 128)]
    private string $externalId;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $system, string $entityType, int $entityId, string $externalId)
    {
        $system = MachineCode::assertValid($system);
        $entityType = MachineCode::assertValid($entityType);
        if (\strlen($system) > 32 || \strlen($entityType) > 32) {
            throw new InvalidArgumentException('system і entity_type — до 32 символів');
        }
        if ($entityId <= 0) {
            throw new InvalidArgumentException('entity_id має бути ідентифікатором збереженої сутності');
        }
        $externalId = trim($externalId);
        if ('' === $externalId || \strlen($externalId) > 128) {
            throw new InvalidArgumentException('external_id має бути непорожнім і не довшим за 128 байтів');
        }
        $this->system = $system;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->externalId = $externalId;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSystem(): string
    {
        return $this->system;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

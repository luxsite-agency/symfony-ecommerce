<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Changeset;

use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Plan\ChangesetPlan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Подання пакета для REST і MCP (E15 PR-4): status — effectiveStatus (прострочений proposed читається як expired, як і на
 * сторінці), url — абсолютна адреса сторінки пакета в адмінці (хост admin_host): саме її Claude Code показує менеджеру,
 * застосувати можна лише там.
 */
final class ChangesetView
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
    ) {
    }

    public function url(Changeset $changeset): string
    {
        return 'https://'.$this->adminHost.$this->urls->generate('admin_changeset_detail', ['entityId' => $changeset->getId()], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /** @return array<string, mixed> */
    public function full(Changeset $changeset): array
    {
        return $this->summary($changeset) + [
            'operations' => $changeset->getOperations(),
            'diff' => $changeset->getDiff(),
            'warnings' => $changeset->getWarnings(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Changeset $changeset): array
    {
        $status = $changeset->effectiveStatus();

        return [
            'id' => $changeset->getId(),
            'status' => $status->value,
            'title' => $changeset->getTitle(),
            'url' => $this->url($changeset),
            'source' => $changeset->getSource()->value,
            'author' => $changeset->getAuthor(),
            'operations_count' => \count($changeset->getOperations()),
            'has_price_operations' => $changeset->hasPriceOperations(),
            'risk_flags' => $changeset->getRiskFlags(),
            'revert_of' => $changeset->getRevertOf()?->getId(),
            'created_at' => $changeset->getCreatedAt()->format(\DATE_ATOM),
            'expires_at' => $changeset->getExpiresAt()->format(\DATE_ATOM),
            'applied_at' => $changeset->getAppliedAt()?->format(\DATE_ATOM),
            'applied_by' => $changeset->getAppliedByName(),
            'rejected_at' => $changeset->getRejectedAt()?->format(\DATE_ATOM),
            'rejected_by' => $changeset->getRejectedByName(),
        ];
    }

    /**
     * Dry-run без збереження: те саме, що план CLI.
     *
     * @return array<string, mixed>
     */
    public static function plan(ChangesetPlan $plan): array
    {
        return ['dry_run' => true] + $plan->toArray();
    }
}

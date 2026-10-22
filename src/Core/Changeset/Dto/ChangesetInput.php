<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Вхід пакета змін: {"title": "…", "operations": [...]}. Той самий DTO для REST (PR-4, #[MapRequestPayload]), MCP (PR-5)
 * і команди changeset:dry-run. Ліміт кількості операцій (app.ai_changeset_max_ops → 413) перевіряє OperationParser ще до розбору.
 */
final readonly class ChangesetInput
{
    /** @param list<Operation> $operations */
    public function __construct(
        #[Assert\NotBlank(message: 'changeset.changeset.title.not_blank')]
        #[Assert\Length(max: 255, maxMessage: 'changeset.changeset.title.max_length')]
        public string $title,
        #[Assert\Count(min: 1, minMessage: 'changeset.changeset.operations.min_count')]
        #[Assert\Valid]
        public array $operations,
    ) {
    }
}

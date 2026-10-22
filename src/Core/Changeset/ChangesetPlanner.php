<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use App\Core\Changeset\Dto\ChangesetInput;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Changeset\Plan\ChangesetPlan;
use App\Core\Changeset\Validate\ChangesetEvaluator;
use App\Core\Security\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dry-run і створення пакета (E15 PR-3): розбір схеми → оцінка на завантажених сутностях без flush → EntityManager очищається
 * (жодних побічних ефектів: ні рядків журналу, ні 301, ні повідомлень у черзі) → при відсутності помилок пакет зберігається
 * як proposed зі строком app.ai_changeset_ttl. Помилки → ChangesetValidationFailed (422), понад ліміт операцій → 413.
 * Увага: після dry-run EntityManager очищено повністю — сутності, завантажені раніше в тому ж запиті (користувач,
 * API-токен), від'єднані; зміни в них після цього flush не збереже (ChangesetAuthor тому несе id, а не User).
 */
final class ChangesetPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperationParser $parser,
        private readonly ChangesetEvaluator $evaluator,
        #[Autowire('%app.ai_changeset_ttl%')] private readonly string $ttl,
    ) {
    }

    /**
     * Лише перевірка: план з усіма знахідками, нічого не записується.
     *
     * @param array<string, mixed> $data
     */
    public function dryRun(array $data): ChangesetPlan
    {
        $input = $this->parser->parse($data);

        return $this->evaluateAndClear($input);
    }

    /**
     * Dry-run і збереження пакета.
     *
     * @param array<string, mixed> $data
     *
     * @throws ChangesetValidationFailed
     */
    public function propose(array $data, ChangesetAuthor $author, ?Changeset $revertOf = null): Changeset
    {
        $input = $this->parser->parse($data);
        $plan = $this->evaluateAndClear($input);
        if ($plan->hasErrors()) {
            throw new ChangesetValidationFailed($plan->errors());
        }
        $now = new DateTimeImmutable();
        $changeset = new Changeset(
            $this->em->getReference(User::class, $author->userId),
            $author->name,
            $author->tokenId,
            $author->source,
            $plan->title,
            $plan->operations(),
            $plan->diffArray(),
            $plan->warningsArray(),
            $plan->riskFlags(),
            $now->modify('+'.$this->ttl),
            $now,
        );
        if (null !== $revertOf) {
            $changeset->setRevertOf($this->em->getReference(Changeset::class, (int) $revertOf->getId()));
        }
        $this->em->persist($changeset);
        $this->em->flush();

        return $changeset;
    }

    private function evaluateAndClear(ChangesetInput $input): ChangesetPlan
    {
        try {
            return $this->evaluator->evaluate($input->title, $input->operations);
        } finally {
            // сеттери вже змінили завантажені сутності — забути їх, щоб жоден подальший flush їх не записав
            $this->em->clear();
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AdminAuditLog;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditActor;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Event\ChangesetApplied;
use App\Core\Changeset\Exception\ChangesetConflict;
use App\Core\Changeset\Exception\ChangesetNotApplicable;
use App\Core\Changeset\Exception\RiskConfirmationRequired;
use App\Core\Changeset\Plan\Issue;
use App\Core\Changeset\Validate\ChangesetEvaluator;
use App\Core\Changeset\Validate\ConflictBlame;
use App\Core\Changeset\Validate\IssueMessage;
use App\Core\Security\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Застосування пакета людиною в адмінці (рішення власника 11.10, п. 1): в одній транзакції рядок пакета і цільові сутності
 * беруться FOR UPDATE, операції оцінюються повторно на свіжих даних (expect, домен, ціни) — розбіжність або нові ризикові
 * прапорці → ChangesetConflict (409) з «хто і коли» з журналу; ризикові прапорці без підтвердження → 422 (п. 4). Flush іде під
 * AdminAuditContext::runAs з changeset_id — рядки по полях пише слухач #68, тут лише підсумковий рядок apply з прапорцями й
 * фактом підтвердження. Наслідки (авто-301, read-index, ReindexProducts) — наявними слухачами. Повноваження перевіряє контролер.
 * Стан пакета (applied/rejected/expired) пишеться лише під FOR UPDATE рядка пакета (рев'ю C #72, M1): «Відхилити» чи
 * прострочення під час чужого «Застосувати» чекають на блокуванні й бачать уже applied. token_id у журналі — з пакета (M2).
 * Після commit — подія ChangesetApplied (лист відповідальним за ціни).
 */
final class ChangesetApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperationParser $parser,
        private readonly ChangesetEvaluator $evaluator,
        private readonly AdminAuditContext $audit,
        private readonly AdminAuditLog $log,
        private readonly ConflictBlame $blame,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @throws ChangesetNotApplicable
     * @throws RiskConfirmationRequired
     * @throws ChangesetConflict
     */
    public function apply(Changeset $changeset, User $by, bool $riskConfirmed = false, ?string $ip = null): Changeset
    {
        $now = new DateTimeImmutable();
        // попередня перевірка без запису: стан у БД міняється лише під блокуванням нижче
        $this->assertProposed($changeset, $now);
        if ($changeset->hasRisk() && !$riskConfirmed) {
            throw new RiskConfirmationRequired($changeset->getRiskFlags());
        }
        $id = (int) $changeset->getId();
        // ліміт операцій перевірено при створенні; зменшення параметра не має робити старі пакети незастосовними
        $input = $this->parser->parse(['title' => $changeset->getTitle(), 'operations' => array_map(self::stripTarget(...), $changeset->getOperations())], enforceLimit: false);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // другий клік «Застосувати» чекає на цьому SELECT … FOR UPDATE і бачить уже applied
            $this->em->refresh($changeset, LockMode::PESSIMISTIC_WRITE);
            $this->assertApplicableLocked($changeset, $now);

            $plan = $this->evaluator->evaluate($changeset->getTitle(), $input->operations, lock: true);
            $conflicts = $this->conflicts($changeset, $plan->errors(), $plan->riskFlags());
            if ([] !== $conflicts) {
                throw new ChangesetConflict($conflicts);
            }
            // токен — той, що створив пакет (добовий ліміт цінових операцій рахується по token_id журналу)
            $actor = AuditActor::user($by, $changeset->getSource(), $ip, $changeset->getTokenId(), $id);
            $this->audit->runAs($actor, function () use ($changeset, $by, $riskConfirmed, $now): void {
                $changeset->markApplied($by, $riskConfirmed, $now);
                $this->em->flush();
            });
            $this->log->write($actor, [[
                'action' => AuditAction::Apply,
                'entity_type' => 'changeset',
                'entity_id' => $id,
                'entity_label' => $changeset->getTitle(),
                'locale' => null,
                'field' => null,
                'before' => null,
                'after' => ['operations' => \count($changeset->getOperations()), 'risk_flags' => $changeset->getRiskFlags(), 'risk_confirmed' => $changeset->hasRisk(), 'revert_of' => $changeset->getRevertOf()?->getId()],
            ]], $now);
            $connection->commit();
        } catch (Throwable $e) {
            $this->rollBack($connection, $e);
        }
        $this->events->dispatch(new ChangesetApplied($id, $by->getId(), $changeset->hasPriceOperations()));

        return $changeset;
    }

    /** Відхилити запропонований пакет: стан і рядок журналу — під тим самим FOR UPDATE, що й застосування. */
    public function reject(Changeset $changeset, User $by, ?string $ip = null): Changeset
    {
        $now = new DateTimeImmutable();
        $this->assertProposed($changeset, $now);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($changeset, LockMode::PESSIMISTIC_WRITE);
            $this->assertApplicableLocked($changeset, $now);
            $changeset->markRejected($by, $now);
            $this->em->flush();
            $this->log->write(AuditActor::user($by, $changeset->getSource(), $ip, $changeset->getTokenId(), (int) $changeset->getId()), [[
                'action' => AuditAction::Reject,
                'entity_type' => 'changeset',
                'entity_id' => (int) $changeset->getId(),
                'entity_label' => $changeset->getTitle(),
                'locale' => null,
                'field' => null,
                'before' => null,
                'after' => null,
            ]], $now);
            $connection->commit();
        } catch (Throwable $e) {
            $this->rollBack($connection, $e);
        }

        return $changeset;
    }

    /**
     * Перевірка стану до транзакції. Прострочений proposed фіксується умовним UPDATE … WHERE status = 'proposed' — без
     * блокування, але й без шансу перекрити applied, який тим часом записав чужий apply.
     */
    private function assertProposed(Changeset $changeset, DateTimeImmutable $now): void
    {
        if ($changeset->isExpired($now)) {
            $this->em->getConnection()->executeStatement('UPDATE admin_changeset SET status = ? WHERE id = ? AND status = ?', [ChangesetStatus::Expired->value, (int) $changeset->getId(), ChangesetStatus::Proposed->value]);
            $changeset->markExpired();
        }
        if (!$changeset->isProposed($now)) {
            throw new ChangesetNotApplicable((int) $changeset->getId(), $changeset->effectiveStatus($now));
        }
    }

    /** Під FOR UPDATE: прострочений proposed фіксується як expired (рядок наш, applied він не перекриє), далі як assertProposed. */
    private function assertApplicableLocked(Changeset $changeset, DateTimeImmutable $now): void
    {
        if ($changeset->isExpired($now)) {
            $changeset->markExpired();
            $this->em->flush();
        }
        $this->assertProposed($changeset, $now);
    }

    /**
     * Відкат транзакції й очищення EntityManager; deadlock/lock wait (паралельне застосування з перетином цілей) — як
     * конфлікт 409 «спробуйте ще раз», не 500.
     *
     * @throws Throwable
     */
    private function rollBack(Connection $connection, Throwable $e): never
    {
        $connection->rollBack();
        if ($this->em->isOpen()) {
            $this->em->clear();
        }
        if ($e instanceof RetryableException) {
            throw new ChangesetConflict([Issue::error(null, 'retry', IssueMessage::of('changeset.issue.retry', [], 'Паралельна зміна тих самих даних — спробуйте ще раз'))], $e);
        }
        throw $e;
    }

    /**
     * Помилки повторної оцінки (конфлікти expect — з «хто/коли» з журналу після створення пакета) і нові ризикові прапорці.
     *
     * @param list<Issue>  $errors
     * @param list<string> $riskFlags
     *
     * @return list<Issue>
     */
    private function conflicts(Changeset $changeset, array $errors, array $riskFlags): array
    {
        $issues = $this->blame->enrich($errors, ConflictBlame::targetsFromDiff($changeset->getDiff()), $changeset->getCreatedAt());
        $new = array_values(array_diff($riskFlags, $changeset->getRiskFlags()));
        if ([] !== $new) {
            $issues[] = Issue::error(null, 'risk_changed', IssueMessage::of('changeset.issue.risk_changed', ['flags' => implode(', ', $new)], \sprintf('З моменту створення пакета з\'явились нові ризики: %s. Створіть пакет заново', implode(', ', $new))), ['risk_flags' => $riskFlags]);
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function stripTarget(array $operation): array
    {
        unset($operation['target']);

        return $operation;
    }
}

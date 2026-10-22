<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\ImportRunStatus;
use App\Core\Shared\Text\MachineCode;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Запуск імпорту (ADR D10: run report): джерело, лічильники, помилки. Незавершений run джерела блокує новий
 * (разом із lock), бо чанки обробляються асинхронно вже після виходу CLI.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_import_run')]
#[ORM\Index(name: 'idx_catalog_import_run_source_status', columns: ['source', 'status'])]
class ImportRun
{
    public const int MAX_ERRORS = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 16, enumType: ImportRunStatus::class)]
    private ImportRunStatus $status = ImportRunStatus::Running;

    #[ORM\Column(length: 8)]
    private string $mode;

    #[ORM\Column]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $totalRows = 0;

    #[ORM\Column]
    private int $invalidRows = 0;

    #[ORM\Column]
    private int $totalChunks = 0;

    #[ORM\Column]
    private int $processedChunks = 0;

    #[ORM\Column]
    private int $productsCreated = 0;

    #[ORM\Column]
    private int $productsUpdated = 0;

    #[ORM\Column]
    private int $productsUnchanged = 0;

    #[ORM\Column]
    private int $variantsRepriced = 0;

    #[ORM\Column]
    private int $variantsDisappeared = 0;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $errors = [];

    public function __construct(string $source, bool $sync)
    {
        $this->source = MachineCode::assertValid($source);
        if (\strlen($this->source) > 32) {
            throw new InvalidArgumentException('Код джерела — до 32 символів');
        }
        $this->mode = $sync ? 'sync' : 'async';
        $this->startedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getStatus(): ImportRunStatus
    {
        return $this->status;
    }

    public function isSync(): bool
    {
        return 'sync' === $this->mode;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function recordStaged(int $rows, int $invalid, int $chunks): void
    {
        $this->totalRows += $rows;
        $this->invalidRows += $invalid;
        $this->totalChunks += $chunks;
    }

    public function addError(string $message): void
    {
        if (\count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = mb_substr($message, 0, 500);
        }
    }

    public function recordChunk(int $created, int $updated, int $unchanged, int $repriced): void
    {
        $this->productsCreated += $created;
        $this->productsUpdated += $updated;
        $this->productsUnchanged += $unchanged;
        $this->variantsRepriced += $repriced;
    }

    public function complete(int $disappeared): void
    {
        $this->variantsDisappeared = $disappeared;
        $this->status = ImportRunStatus::Completed;
        $this->finishedAt = new DateTimeImmutable();
    }

    public function fail(string $reason): void
    {
        $this->addError($reason);
        $this->status = ImportRunStatus::Failed;
        $this->finishedAt = new DateTimeImmutable();
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getInvalidRows(): int
    {
        return $this->invalidRows;
    }

    public function getTotalChunks(): int
    {
        return $this->totalChunks;
    }

    public function getProcessedChunks(): int
    {
        return $this->processedChunks;
    }

    public function getProductsCreated(): int
    {
        return $this->productsCreated;
    }

    public function getProductsUpdated(): int
    {
        return $this->productsUpdated;
    }

    public function getProductsUnchanged(): int
    {
        return $this->productsUnchanged;
    }

    public function getVariantsRepriced(): int
    {
        return $this->variantsRepriced;
    }

    public function getVariantsDisappeared(): int
    {
        return $this->variantsDisappeared;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string, int|string|null> для консолі й логів */
    public function summary(): array
    {
        return [
            'run' => $this->id,
            'source' => $this->source,
            'status' => $this->status->value,
            'rows' => $this->totalRows,
            'invalid' => $this->invalidRows,
            'chunks' => \sprintf('%d/%d', $this->processedChunks, $this->totalChunks),
            'created' => $this->productsCreated,
            'updated' => $this->productsUpdated,
            'unchanged' => $this->productsUnchanged,
            'repriced' => $this->variantsRepriced,
            'disappeared' => $this->variantsDisappeared,
            'errors' => \count($this->errors),
        ];
    }
}

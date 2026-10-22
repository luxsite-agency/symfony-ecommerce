<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use App\Core\Changeset\Dto\ChangesetInput;
use App\Core\Changeset\Dto\Operation;
use App\Core\Changeset\Dto\RemovePriceTier;
use App\Core\Changeset\Dto\SetPriceTier;
use App\Core\Changeset\Dto\SetVariantPrice;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Changeset\Exception\TooManyOperations;
use App\Core\Changeset\Plan\Issue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Схема пакета: сирий масив (JSON з REST/MCP/CLI) → ChangesetInput з типізованими операціями. Невідомий op, зайвий ключ,
 * відсутній обов'язковий ключ, не той тип — помилка схеми з індексом операції (422). Понад app.ai_changeset_max_ops
 * операцій — 413 ще до розбору. Усе, що потребує БД (сутності, expect, домен), — далі в ChangesetEvaluator.
 */
final class OperationParser
{
    /** @var array<string, list<string>> ключі, які мають бути в JSON навіть зі значенням null (Serializer підставив би null мовчки) */
    private const array REQUIRED_KEYS = [
        SetVariantPrice::TYPE => ['old_price'],
        SetPriceTier::TYPE => ['customer_group'],
        RemovePriceTier::TYPE => ['customer_group'],
    ];

    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
        #[Autowire('%app.ai_changeset_max_ops%')] private readonly int $maxOperations,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws TooManyOperations
     * @throws ChangesetValidationFailed
     */
    public function parse(array $data, bool $enforceLimit = true): ChangesetInput
    {
        $operations = $data['operations'] ?? null;
        if ($enforceLimit && \is_array($operations) && $this->maxOperations > 0 && \count($operations) > $this->maxOperations) {
            throw new TooManyOperations(\count($operations), $this->maxOperations);
        }
        if (!\is_array($operations) || !array_is_list($operations)) {
            throw new ChangesetValidationFailed([Issue::error(null, 'schema', 'operations: список операцій')]);
        }
        $title = $data['title'] ?? null;
        if (!\is_string($title)) {
            throw new ChangesetValidationFailed([Issue::error(null, 'schema', 'title: рядок')]);
        }
        $extra = array_diff(array_keys($data), ['title', 'operations']);
        if ([] !== $extra) {
            throw new ChangesetValidationFailed([Issue::error(null, 'schema', \sprintf('Зайві ключі: %s', implode(', ', $extra)))]);
        }
        $issues = [];
        $parsed = [];
        // без phpdoc-парсера Serializer не знає тип елементів списку, тож операції денормалізуються поштучно (discriminator op)
        foreach ($operations as $index => $operation) {
            if (!\is_array($operation)) {
                $issues[] = Issue::error($index, 'schema', 'операція — об\'єкт з ключем op');
                continue;
            }
            foreach (self::REQUIRED_KEYS[$operation['op'] ?? ''] ?? [] as $key) {
                if (!\array_key_exists($key, $operation)) {
                    $issues[] = Issue::error($index, 'schema', \sprintf('%s: обов\'язковий ключ (може бути null)', $key));
                    continue 2;
                }
            }
            try {
                /** @var Operation $dto */
                $dto = $this->denormalizer->denormalize($operation, Operation::class, 'json', [
                    AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                    DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
                ]);
                $parsed[] = $dto;
            } catch (PartialDenormalizationException $e) {
                foreach ($e->getErrors() as $error) {
                    $issues[] = Issue::error($index, 'schema', self::describe($error->getPath(), $error->getMessage()));
                }
            } catch (SerializerException $e) {
                $issues[] = Issue::error($index, 'schema', $e->getMessage());
            }
        }
        if ([] !== $issues) {
            throw new ChangesetValidationFailed($issues);
        }
        $input = new ChangesetInput($title, $parsed);
        foreach ($this->validator->validate($input) as $violation) {
            /** @var ConstraintViolationInterface $violation */
            $issues[] = Issue::error(self::indexFromPath($violation->getPropertyPath()), 'schema', self::describe($violation->getPropertyPath(), (string) $violation->getMessage()));
        }
        if ([] !== $issues) {
            throw new ChangesetValidationFailed($issues);
        }

        return $input;
    }

    /** operations[3].price → 3; інакше null */
    private static function indexFromPath(?string $path): ?int
    {
        if (null !== $path && preg_match('/^operations\[(\d+)\]/', $path, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private static function describe(?string $path, string $message): string
    {
        $path = (string) preg_replace('/^operations\[\d+\]\.?/', '', (string) $path);

        return '' === $path || str_contains($message, $path.':') ? $message : $path.': '.$message;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Shared\Translation\TranslatableException;
use Stringable;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Текст знахідки dry-run (Issue) у двох поданнях: український рядок — для REST/MCP/CLI (Claude Code читає саме його, як і
 * досі), ключ changeset.issue.* з параметрами — для сторінки пакета в адмінці мовою інтерфейсу. Так само, як
 * TranslatableException для доменних винятків: ключа нема чи його нема в перекладах — показується український текст.
 * Параметри — скаляри або вкладені IssueMessage (назва типу, текст знахідки в «хто/коли»); для збереження в warnings
 * пакета — toArray()/fromArray().
 */
final readonly class IssueMessage implements TranslatableInterface, Stringable
{
    public const string DOMAIN = 'admin';

    /** @param array<string, scalar|self|null> $parameters */
    public function __construct(public string $text, public string $key = '', public array $parameters = [])
    {
    }

    /** @param array<string, scalar|self|null> $parameters */
    public static function of(string $key, array $parameters, string $text): self
    {
        return new self($text, $key, $parameters);
    }

    /** Текст доменного винятку: ключ error.* і параметри, якщо виняток їх має (CatalogInvariantViolation), інакше лише текст. */
    public static function fromException(Throwable $e): self
    {
        return $e instanceof TranslatableException ? new self($e->getMessage(), $e->getMessageKey(), $e->getMessageParameters()) : new self($e->getMessage());
    }

    public function __toString(): string
    {
        return $this->text;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        if ('' === $this->key) {
            return $this->text;
        }
        $parameters = array_map(static fn (mixed $p): mixed => $p instanceof self ? $p->trans($translator, $locale) : $p, $this->parameters);
        $text = $translator->trans($this->key, $parameters, self::DOMAIN, $locale);

        // ключа нема в перекладах — краще український текст, ніж сирий ключ
        return $text === $this->key ? $this->text : $text;
    }

    /**
     * Подання для збереження поруч із message (warnings пакета); вкладені — масивом з ключем text.
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'params' => array_map(static fn (mixed $p): mixed => $p instanceof self ? ['text' => $p->text] + $p->toArray() : $p, $this->parameters)];
    }

    /** Відновити зі збереженого; $data не масив (старі пакети без ключа) — лише український текст. */
    public static function fromArray(string $text, mixed $data): self
    {
        if (!\is_array($data) || !\is_string($data['key'] ?? null)) {
            return new self($text);
        }
        $parameters = [];
        foreach (\is_array($data['params'] ?? null) ? $data['params'] : [] as $name => $value) {
            $parameters[(string) $name] = \is_array($value) ? self::fromArray(\is_string($value['text'] ?? null) ? $value['text'] : '', $value) : (\is_scalar($value) || null === $value ? $value : null);
        }

        return new self($text, $data['key'], $parameters);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Changeset\Plan\Issue;
use App\Core\Shared\Html\ContentHtml;
use App\Core\Shared\Text\Slug;
use InvalidArgumentException;

/**
 * Текстові поля пакета: HTML через той самий санітайзер, що й адмінка (результат ≠ надісланому → попередження, пишеться
 * очищене), довжини колонок, формат слага, типографічні попередження без автозаміни (тире-сполучники, подвійні пробіли,
 * прямі лапки) — менеджер бачить їх на сторінці пакета й вирішує сам. Тексти — IssueMessage (ключі changeset.issue.*).
 */
final class ContentRules
{
    public function __construct(private readonly ContentHtml $html)
    {
    }

    /**
     * Нормалізує значення поля й додає знахідки; повертає значення, яке буде записане.
     *
     * @param list<Issue> $issues
     */
    public function normalize(int $index, string $type, string $field, ?string $value, array &$issues): ?string
    {
        if (null === $value) {
            return null;
        }
        if ('slug' === $field) {
            $value = trim($value);
            if ('' === $value) {
                return null;
            }
            try {
                return Slug::assertValid($value);
            } catch (InvalidArgumentException) {
                $issues[] = Issue::error($index, 'invalid_slug', IssueMessage::of('changeset.issue.invalid_slug', ['field' => $field], \sprintf('%s: слаг — латиниця в нижньому регістрі, цифри й дефіси між ними', $field)));

                return $value;
            }
        }
        if (TranslationFields::isHtml($type, $field)) {
            if ($this->html->exceedsLimit($value)) {
                $issues[] = Issue::error($index, 'too_long', IssueMessage::of('changeset.issue.too_long_bytes', ['field' => $field, 'max' => (string) ContentHtml::MAX_INPUT_LENGTH], \sprintf('%s: довше за %d байтів', $field, ContentHtml::MAX_INPUT_LENGTH)));

                return $value;
            }
            $clean = $this->html->clean($value);
            if ($clean !== trim($value)) {
                $issues[] = Issue::warning($index, 'html_sanitized', IssueMessage::of('changeset.issue.html_sanitized', ['field' => $field], \sprintf('%s: HTML очищено за правилами магазину (зняті теги чи атрибути)', $field)));
            }
            $this->typography($index, $field, $clean, $issues);

            return $clean;
        }
        $value = trim($value);
        if ('' === $value) {
            $value = null;
        }
        $max = TranslationFields::maxLength($type, $field);
        if (null !== $value && null !== $max) {
            // колонки TEXT (65535) міряються в байтах, VARCHAR — у символах: 40 000 кириличних символів у TEXT не влізуть
            $bytes = $max >= TranslationFields::TEXT_BYTES;
            if (($bytes ? \strlen($value) : mb_strlen($value)) > $max) {
                $issues[] = Issue::error($index, 'too_long', IssueMessage::of($bytes ? 'changeset.issue.too_long_bytes' : 'changeset.issue.too_long_chars', ['field' => $field, 'max' => (string) $max], \sprintf('%s: довше за %d %s', $field, $max, $bytes ? 'байтів' : 'символів')));
            }
        }
        $this->typography($index, $field, $value, $issues);

        return $value;
    }

    /** @param list<Issue> $issues */
    private function typography(int $index, string $field, ?string $value, array &$issues): void
    {
        if (null === $value) {
            return;
        }
        if (preg_match('/\S\s[—–-]\s\S/u', $value)) {
            $issues[] = Issue::warning($index, 'typography_dash', IssueMessage::of('changeset.issue.typography_dash', ['field' => $field], \sprintf('%s: тире як сполучник між словами — у текстах магазину його не вживають', $field)));
        }
        if (preg_match('/[^\s>]  +[^\s<]/u', $value)) {
            $issues[] = Issue::warning($index, 'double_space', IssueMessage::of('changeset.issue.double_space', ['field' => $field], \sprintf('%s: подвійні пробіли', $field)));
        }
        // у санітизованому HTML лапки екрановані як &#34; / &quot;
        if (preg_match('/(^|[\s(>])(?:"|&quot;|&#34;)[^"&<]+(?:"|&quot;|&#34;)/u', $value)) {
            $issues[] = Issue::warning($index, 'straight_quotes', IssueMessage::of('changeset.issue.straight_quotes', ['field' => $field], \sprintf('%s: прямі лапки замість «ялинок»', $field)));
        }
    }
}

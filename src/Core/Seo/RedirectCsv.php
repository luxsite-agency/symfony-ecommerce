<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\Exception\InvalidRedirect;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * CSV менеджера редиректів, роздільник «;»: імпорт — source;code;target (рядок-заголовок «source;…» пропускається,
 * зайві колонки ігноруються, тож експорт імпортується назад як є). Кожен рядок — RedirectManager::save() по черзі:
 * перевірка циклів бачить і правила з попередніх рядків файлу. Хибні рядки не зупиняють імпорт — вони у звіті:
 * message — українською (консоль, тести), trans — те саме для адмінки мовою інтерфейсу (InvalidRedirect теж перекладний).
 */
final class RedirectCsv
{
    public const array HEADER = ['source', 'code', 'target', 'kind', 'hits', 'last_hit_at'];
    /** Синхронний імпорт з адмінки: кожен рядок — перевірка живої адреси, ланцюга й запис; більше — 504 і частковий імпорт (рев'ю #37, п. 4). */
    public const int WEB_MAX_ROWS = 5000;

    public function __construct(private readonly RedirectManager $redirects, private readonly Connection $db)
    {
    }

    /**
     * @param int|null $maxRows null — без ліміту (консольна команда)
     *
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string, trans: TranslatableInterface}>}
     */
    public function import(string $file, ?int $maxRows = self::WEB_MAX_ROWS, bool $allowExternal = true): array
    {
        $handle = @fopen($file, 'r');
        if (false === $handle) {
            throw new RuntimeException('Не вдалося прочитати файл');
        }
        $report = ['created' => 0, 'updated' => 0, 'errors' => []];
        $line = 0;
        $rows = 0;
        try {
            while (false !== ($row = fgetcsv($handle, null, ';', '"', ''))) {
                ++$line;
                if (1 === $line && isset($row[0])) {
                    $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $row = array_map(static fn (?string $v): string => trim((string) $v), $row);
                if ([''] === $row || [] === array_filter($row, static fn (string $v): bool => '' !== $v)) {
                    continue;
                }
                if (1 === $line && 'source' === strtolower($row[0])) {
                    continue;
                }
                if (null !== $maxRows && ++$rows > $maxRows) {
                    $report['errors'][] = ['line' => $line, 'message' => \sprintf('Понад %d рядків — решту файлу пропущено (більші файли — seo:redirects:import)', $maxRows), 'trans' => new TranslatableMessage('redirect.import.error.too_many_rows', ['max' => (string) $maxRows], 'admin')];
                    break;
                }
                if (!mb_check_encoding(implode(';', $row), 'UTF-8')) {
                    $report['errors'][] = ['line' => $line, 'message' => 'Рядок не в UTF-8: збережіть файл як «CSV UTF-8»', 'trans' => new TranslatableMessage('redirect.import.error.not_utf8', [], 'admin')];
                    continue;
                }
                $code = RedirectCode::tryFrom((int) ($row[1] ?? 0));
                if (null === $code || !ctype_digit($row[1] ?? '')) {
                    $report['errors'][] = ['line' => $line, 'message' => \sprintf('Код «%s»: дозволено 301, 302, 404, 410', $row[1] ?? ''), 'trans' => new TranslatableMessage('redirect.import.error.bad_code', ['code' => $row[1] ?? ''], 'admin')];
                    continue;
                }
                try {
                    $this->redirects->save($row[0], $code, $row[2] ?? null, allowExternal: $allowExternal) ? ++$report['created'] : ++$report['updated'];
                } catch (InvalidRedirect $e) {
                    $report['errors'][] = ['line' => $line, 'message' => $e->getMessage(), 'trans' => $e];
                }
            }
        } finally {
            fclose($handle);
        }

        return $report;
    }

    /** @param resource $out */
    public function export($out): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // Excel відкриває UTF-8 правильно лише з BOM; імпорт його знімає
        fputcsv($out, self::HEADER, ';', '"', '');
        foreach ($this->db->iterateNumeric('SELECT source, code, target, kind, hits, last_hit_at FROM seo_redirect ORDER BY source') as $row) {
            fputcsv($out, array_map(static fn (mixed $v): string => (string) $v, $row), ';', '"', '');
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Command;

use App\Core\Shared\Html\ContentHtml;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разова чистка вже збережених HTML-текстів білим списком ContentHtml (консиліум безпеки 10.10 п. 2): описи товарів
 * і категорій, сторінки, записи блогу — усе, що шаблони виводять |raw і що могло потрапити в БД до появи санітизації
 * (імпорт фідів, адмінка). Статті зовнішньої контент-панелі (studio_group_id) пропускаються — їх чистить StudioHtml. Читається пакетами за id, кожен пакет пишеться однією транзакцією. Спершу --dry-run: він
 * показує, скільки рядків зміниться, і приклади; частина змін — лише нормалізація розмітки (<br> → <br />, лапки атрибутів).
 */
#[AsCommand(name: 'catalog:html:sanitize', description: 'Прогнати збережені HTML-тексти (описи товарів і категорій, сторінки, записи) через білий список ContentHtml')]
final class SanitizeHtmlCommand
{
    /** таблиця → [колонки з HTML, який сайт виводить |raw; додаткова умова WHERE] */
    private const array COLUMNS = [
        'catalog_product_translation' => [['description', 'short_description'], null],
        'catalog_category_translation' => [['description'], null],
        'content_page_translation' => [['body'], null],
        // статті зовнішньої контент-панелі (studio_group_id) чистить StudioHtml своїм списком — не чіпати, інакше розійдуться з джерелом
        'content_post_translation' => [['body'], 'post_id IN (SELECT id FROM content_post WHERE studio_group_id IS NULL)'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ContentHtml $html,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Лише порахувати і показати приклади, нічого не писати')] bool $dryRun = false,
        #[Option(description: 'Рядків у пакеті')] int $batch = 500,
    ): int {
        $batch = max(1, $batch);
        $total = 0;
        foreach (self::COLUMNS as $table => [$columns, $where]) {
            foreach ($columns as $column) {
                $changed = 0;
                $examples = [];
                for ($after = 0; [] !== ($rows = $this->chunk($table, $column, $where, $after, $batch)); $after = (int) end($rows)['id']) {
                    $updates = [];
                    foreach ($rows as $row) {
                        $clean = $this->html->clean((string) $row[$column]);
                        if ($clean === $row[$column]) {
                            continue;
                        }
                        $updates[(int) $row['id']] = $clean;
                        if (\count($examples) < 3) {
                            $examples[] = \sprintf('#%d: %s → %s', (int) $row['id'], self::excerpt((string) $row[$column]), self::excerpt((string) $clean));
                        }
                    }
                    if (!$dryRun && [] !== $updates) {
                        $this->db->transactional(function () use ($table, $column, $updates): void {
                            foreach ($updates as $id => $clean) {
                                $this->db->update($table, [$column => $clean], ['id' => $id]);
                            }
                        });
                    }
                    $changed += \count($updates);
                }
                $io->writeln(\sprintf('%s.%s: %s %d', $table, $column, $dryRun ? 'зміниться' : 'оновлено', $changed));
                foreach ($examples as $example) {
                    $io->text('  '.$example);
                }
                $total += $changed;
            }
        }
        $io->success($dryRun ? \sprintf('Dry-run: змінилося б %d рядків. Запуск без --dry-run запише їх.', $total) : \sprintf('Оновлено %d рядків.', $total));

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function chunk(string $table, string $column, ?string $where, int $after, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            \sprintf('SELECT id, %2$s FROM %1$s WHERE id > ? AND %2$s IS NOT NULL AND %2$s <> \'\'%4$s ORDER BY id LIMIT %3$d', $table, $column, $limit, null === $where ? '' : ' AND '.$where),
            [$after],
        );
    }

    private static function excerpt(string $html): string
    {
        $flat = preg_replace('/\s+/u', ' ', $html) ?? $html;

        return mb_strlen($flat) > 80 ? mb_substr($flat, 0, 77).'…' : $flat;
    }
}

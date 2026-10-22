<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Staging-таблиця імпорту (без сутності, поза schema:validate): рядки фіду кладуться сюди швидким multi-row INSERT,
 * далі чанки обробляються батчами SQL (ціна/залишок/sourceUpdatedAt існуючих варіантів) та ORM (новий/змінений контент),
 * а наприкінці одним запитом знаходяться варіанти джерела, яких у фіді більше нема.
 */
final class ImportStaging
{
    public const string TABLE = 'catalog_import_staging';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<ProductImportRow> $rows */
    public function insertChunk(int $runId, int $chunkNo, array $rows): void
    {
        if ([] === $rows) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $payload = json_encode($row->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $hash = $row->contentHash();
            foreach ($row->variants as $variant) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $runId, $chunkNo, $row->externalId, $variant->externalId, $variant->sku, $variant->priceMinor, $variant->oldPriceMinor,
                    $variant->currency, $variant->stock, $variant->barcode, $row->sourceUpdatedAt?->format('Y-m-d H:i:s'), $hash, $payload);
            }
        }
        $sql = 'INSERT INTO '.self::TABLE.' (run_id, chunk_no, product_external_id, variant_external_id, sku, price_minor, old_price_minor, currency, stock, barcode, source_updated_at, content_hash, payload) VALUES '.implode(', ', $values);
        $this->db->executeStatement($sql, $params);
    }

    /**
     * Один рядок картки (payload) на product_external_id чанка — варіанти в payload, тому дублі рядків схлопуються.
     *
     * @return iterable<string, ProductImportRow> contentHash → рядок
     */
    public function rowsOfChunk(int $runId, int $chunkNo): iterable
    {
        // Два кроки: сортування/групування — лише по коротких колонках. GROUP BY … ORDER BY разом із payload сортує цілі
        // рядки, і картка з описом понад sort_buffer_size MySQL 8 (256 КБ) валить чанк помилкою 1038 «Out of sort memory».
        $heads = $this->db->fetchAllAssociative(
            'SELECT MIN(id) AS id, content_hash FROM '.self::TABLE.' WHERE run_id = ? AND chunk_no = ? GROUP BY product_external_id, content_hash ORDER BY MIN(id)',
            [$runId, $chunkNo],
        );
        if ([] === $heads) {
            return;
        }
        $payloads = $this->db->fetchAllKeyValue(
            'SELECT id, payload FROM '.self::TABLE.' WHERE id IN (?)',
            [array_map(static fn (array $h): int => (int) $h['id'], $heads)],
            [ArrayParameterType::INTEGER],
        );
        foreach ($heads as $head) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) $payloads[(int) $head['id']], true, 32, \JSON_THROW_ON_ERROR);
            yield (string) $head['content_hash'] => ProductImportRow::fromArray($data);
        }
    }

    /**
     * Batch-upsert цін/залишків існуючих варіантів чанка (за external_id, у тій самій валюті) одним UPDATE.
     * Повертає кількість варіантів, у яких щось справді змінилось (рахується до UPDATE).
     */
    public function repriceExistingVariants(int $runId, int $chunkNo): int
    {
        $join = 'FROM catalog_product_variant v JOIN '.self::TABLE.' s ON s.variant_external_id = v.external_id AND s.run_id = ? AND s.chunk_no = ? AND s.currency = v.currency';
        $differs = '(v.price_minor <> s.price_minor OR NOT (v.old_price_minor <=> s.old_price_minor) OR v.stock <> s.stock OR (s.barcode IS NOT NULL AND NOT (v.barcode <=> s.barcode)))';
        $changed = (int) $this->db->fetchOne('SELECT COUNT(*) '.$join.' WHERE '.$differs, [$runId, $chunkNo]);
        if ($changed > 0) {
            $this->db->executeStatement(
                'UPDATE catalog_product_variant v JOIN '.self::TABLE.' s ON s.variant_external_id = v.external_id AND s.run_id = ? AND s.chunk_no = ? AND s.currency = v.currency
                 SET v.price_minor = s.price_minor, v.old_price_minor = s.old_price_minor, v.stock = s.stock, v.barcode = COALESCE(s.barcode, v.barcode), v.updated_at = NOW()
                 WHERE '.$differs,
                [$runId, $chunkNo],
            );
        }
        $this->db->executeStatement(
            'UPDATE catalog_product p JOIN catalog_product_variant v ON v.product_id = p.id JOIN '.self::TABLE.' s ON s.variant_external_id = v.external_id AND s.run_id = ? AND s.chunk_no = ?
             SET p.source_updated_at = COALESCE(s.source_updated_at, p.source_updated_at) WHERE s.source_updated_at IS NOT NULL AND NOT (p.source_updated_at <=> s.source_updated_at)',
            [$runId, $chunkNo],
        );

        return $changed;
    }

    /**
     * Варіанти в чужій валюті: batch їх не чіпає, у звіт іде список sku.
     *
     * @return list<string>
     */
    public function currencyMismatches(int $runId, int $chunkNo): array
    {
        /** @var list<string> $skus */
        $skus = $this->db->fetchFirstColumn(
            'SELECT s.sku FROM '.self::TABLE.' s JOIN catalog_product_variant v ON v.external_id = s.variant_external_id WHERE s.run_id = ? AND s.chunk_no = ? AND s.currency <> v.currency',
            [$runId, $chunkNo],
        );

        return $skus;
    }

    /**
     * Зникли з фіду: варіанти товарів цього джерела (ExternalRef system=feed_<source>), чиїх external_id нема в staging
     * запуску, отримують stock = 0. Повертає кількість змінених (з ненульового залишку).
     */
    public function zeroDisappearedVariants(int $runId, string $system): int
    {
        $where = 'v.external_id IS NOT NULL AND v.stock <> 0
            AND EXISTS (SELECT 1 FROM integration_external_ref r WHERE r.system_code = ? AND r.entity_type = ? AND r.entity_id = v.product_id)
            AND NOT EXISTS (SELECT 1 FROM '.self::TABLE.' s WHERE s.run_id = ? AND s.variant_external_id = v.external_id)';
        $params = [$system, 'product', $runId];
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_variant v WHERE '.$where, $params);
        if ($count > 0) {
            $this->db->executeStatement('UPDATE catalog_product_variant v SET v.stock = 0, v.updated_at = NOW() WHERE '.$where, $params);
        }

        return $count;
    }

    public function purge(int $runId): void
    {
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE run_id = ?', [$runId]);
    }

    /** @param list<int> $runIds */
    public function purgeMany(array $runIds): void
    {
        if ([] !== $runIds) {
            $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE run_id IN (?)', [$runIds], [ArrayParameterType::INTEGER]);
        }
    }
}

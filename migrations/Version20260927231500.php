<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ф1, ADR Rev 11 (продуктивність каталогу на 500k, consilium-2026-09-27-bench/critic.md):
 *
 * - п. 3: у catalog_filter_product_category — listed (у товару є listed-варіант), sort_asc / sort_desc (мін. ціна
 *   listed-варіантів; «ціна за запитом» — сентинел INT_MAX для зростання і -1 для спадання, як у CatalogQuery) і два
 *   індекси: сторінка категорії без фільтрів — обхід діапазону індексу замість GROUP BY + filesort усіх варіантів.
 *   Новизна — PK (category_id, product_id). Наявні рядки заповнюються з catalog_filter_variant тут же.
 * - п. 4: catalog_filter_category_summary — зведення фасетів категорії без фільтрів (F0) одним JSON на категорію,
 *   без дельт: повний перерахунок категорії при перебудові й фоново (catalog_filter_category_dirty — черга змінених).
 *
 * Усі catalog_filter_* поза doctrine schema_filter.
 */
final class Version20260927231500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф1 Rev 11: listed/sort_asc/sort_desc у catalog_filter_product_category + 2 індекси, зведення фасетів категорій і черга перерахунку';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE catalog_filter_product_category
              ADD listed    TINYINT(1) NOT NULL DEFAULT 0,
              ADD sort_asc  INT NOT NULL DEFAULT 2147483647,
              ADD sort_desc INT NOT NULL DEFAULT -1,
              ADD KEY idx_fpc_sort_asc (category_id, listed, sort_asc, product_id),
              ADD KEY idx_fpc_sort_desc (category_id, listed, sort_desc, product_id)
        SQL);
        // price_mode у read-index: 0 fixed, 1 from, 2 on_request (CatalogHit::PRICE_*)
        $this->addSql(<<<'SQL'
            UPDATE catalog_filter_product_category pc
            JOIN (
              SELECT product_id, MAX(listed) AS listed,
                     COALESCE(MIN(IF(listed = 1, IF(price_mode = 2, 2147483647, price_minor), NULL)), 2147483647) AS sort_asc,
                     COALESCE(MIN(IF(listed = 1, IF(price_mode = 2, -1, price_minor), NULL)), -1) AS sort_desc
              FROM catalog_filter_variant GROUP BY product_id
            ) a ON a.product_id = pc.product_id
            SET pc.listed = a.listed, pc.sort_asc = a.sort_asc, pc.sort_desc = a.sort_desc
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_filter_category_summary (
              category_id INT UNSIGNED NOT NULL,
              total       INT UNSIGNED NOT NULL,
              facets      JSON NOT NULL,
              computed_at DATETIME(6) NOT NULL,
              PRIMARY KEY (category_id)
            ) ENGINE=InnoDB
        SQL);
        $this->addSql('CREATE TABLE catalog_filter_category_dirty (category_id INT UNSIGNED NOT NULL, queued_at DATETIME(6) NOT NULL, PRIMARY KEY (category_id)) ENGINE=InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_filter_category_dirty');
        $this->addSql('DROP TABLE catalog_filter_category_summary');
        $this->addSql('ALTER TABLE catalog_filter_product_category DROP INDEX idx_fpc_sort_desc, DROP INDEX idx_fpc_sort_asc, DROP COLUMN sort_desc, DROP COLUMN sort_asc, DROP COLUMN listed');
    }
}

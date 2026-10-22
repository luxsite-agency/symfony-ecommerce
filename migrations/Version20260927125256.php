<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927125256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф0: імпорт — catalog_import_run, staging-таблиця catalog_import_staging (без сутності), Product.source_hash';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE catalog_import_run (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, total_rows INT NOT NULL, invalid_rows INT NOT NULL, total_chunks INT NOT NULL, processed_chunks INT NOT NULL, products_created INT NOT NULL, products_updated INT NOT NULL, products_unchanged INT NOT NULL, variants_repriced INT NOT NULL, variants_disappeared INT NOT NULL, errors JSON NOT NULL, INDEX idx_catalog_import_run_source_status (source, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE catalog_product ADD source_hash VARCHAR(40) DEFAULT NULL');
        // Staging імпорту: без Doctrine-сутності (doctrine.dbal.schema_filter), пишеться multi-row INSERT-ом, читається чанками
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_import_staging (
              id BIGINT AUTO_INCREMENT NOT NULL,
              run_id INT NOT NULL,
              chunk_no INT NOT NULL,
              product_external_id VARCHAR(128) NOT NULL,
              variant_external_id VARCHAR(128) NOT NULL,
              sku VARCHAR(64) NOT NULL,
              price_minor INT NOT NULL,
              old_price_minor INT DEFAULT NULL,
              currency CHAR(3) NOT NULL,
              stock NUMERIC(12, 3) NOT NULL,
              barcode VARCHAR(64) DEFAULT NULL,
              source_updated_at DATETIME DEFAULT NULL,
              content_hash CHAR(40) NOT NULL,
              payload JSON NOT NULL,
              INDEX idx_catalog_import_staging_chunk (run_id, chunk_no),
              INDEX idx_catalog_import_staging_variant (run_id, variant_external_id),
              INDEX idx_catalog_import_staging_product (run_id, product_external_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE catalog_import_staging');
        $this->addSql('DROP TABLE catalog_import_run');
        $this->addSql('ALTER TABLE catalog_product DROP source_hash');
    }
}

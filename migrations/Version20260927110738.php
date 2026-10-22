<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ф0: ExternalRef → Core/Integration, CustomerGroup → Core/Customer (рішення власника по PR #1, п. 6).
 * Лише перейменування таблиць та індексів: дані (якщо є) зберігаються, FK catalog_price_tier → customer_group
 * MySQL переносить разом із таблицею.
 */
final class Version20260927110738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф0: перенос таблиць external_ref і customer_group з каталогу в модулі Integration і Customer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE catalog_external_ref TO integration_external_ref');
        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX uniq_catalog_external_ref_external TO uniq_integration_external_ref_external');
        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX uniq_catalog_external_ref_entity TO uniq_integration_external_ref_entity');
        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX idx_catalog_external_ref_entity TO idx_integration_external_ref_entity');

        $this->addSql('RENAME TABLE catalog_customer_group TO customer_group');
        $this->addSql('ALTER TABLE customer_group RENAME INDEX uniq_catalog_customer_group_code TO uniq_customer_group_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_group RENAME INDEX uniq_customer_group_code TO uniq_catalog_customer_group_code');
        $this->addSql('RENAME TABLE customer_group TO catalog_customer_group');

        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX idx_integration_external_ref_entity TO idx_catalog_external_ref_entity');
        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX uniq_integration_external_ref_entity TO uniq_catalog_external_ref_entity');
        $this->addSql('ALTER TABLE integration_external_ref RENAME INDEX uniq_integration_external_ref_external TO uniq_catalog_external_ref_external');
        $this->addSql('RENAME TABLE integration_external_ref TO catalog_external_ref');
    }
}

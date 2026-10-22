<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Керована вітрина головної: власне зображення категорії для плитки (catalog_category.image_id → медіатека) і прапорець
 * товару «Показувати на головній» (catalog_product.featured).
 */
final class Version20260928192144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Вітрина головної: catalog_category.image_id (зображення плитки) і catalog_product.featured (показувати на головній)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_category ADD image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_category ADD CONSTRAINT FK_349BC7DF3DA5256D FOREIGN KEY (image_id) REFERENCES catalog_media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_349BC7DF3DA5256D ON catalog_category (image_id)');
        $this->addSql('ALTER TABLE catalog_product ADD featured TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_catalog_product_featured ON catalog_product (featured)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_category DROP FOREIGN KEY FK_349BC7DF3DA5256D');
        $this->addSql('DROP INDEX IDX_349BC7DF3DA5256D ON catalog_category');
        $this->addSql('ALTER TABLE catalog_category DROP image_id');
        $this->addSql('DROP INDEX idx_catalog_product_featured ON catalog_product');
        $this->addSql('ALTER TABLE catalog_product DROP featured');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Імпорт зображень (бриф Сокіл 77): MediaImporter не качає той самий URL повторно (catalog_media_source) і не дублює
 * файл з однаковим вмістом під іншим URL (catalog_media.content_hash).
 */
final class Version20260928172150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Імпорт зображень за URL: catalog_media.content_hash (дедуплікація за вмістом) і catalog_media_source (sha256 URL → media)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE catalog_media_source (url_hash CHAR(64) NOT NULL, url VARCHAR(2048) NOT NULL, created_at DATETIME NOT NULL, media_id INT NOT NULL, INDEX IDX_D28CDF63EA9FDD75 (media_id), PRIMARY KEY (url_hash)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE catalog_media_source ADD CONSTRAINT FK_D28CDF63EA9FDD75 FOREIGN KEY (media_id) REFERENCES catalog_media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_media ADD content_hash CHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_catalog_media_content_hash ON catalog_media (content_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_media_source DROP FOREIGN KEY FK_D28CDF63EA9FDD75');
        $this->addSql('DROP TABLE catalog_media_source');
        $this->addSql('DROP INDEX idx_catalog_media_content_hash ON catalog_media');
        $this->addSql('ALTER TABLE catalog_media DROP content_hash');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927154651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф1: таблиця setting — налаштування магазину ключ → значення (аналітика, SEO, HTML-коди)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE setting (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, value LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_setting_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE setting');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011174512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Журнал змін адмінки admin_audit_entry (E15 PR-1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_audit_entry (id BIGINT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, user_id INT DEFAULT NULL, actor VARCHAR(180) DEFAULT NULL, token_id INT DEFAULT NULL, source VARCHAR(16) NOT NULL, action VARCHAR(16) NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id INT NOT NULL, entity_label VARCHAR(255) DEFAULT NULL, locale VARCHAR(12) DEFAULT NULL, field VARCHAR(64) DEFAULT NULL, old_value JSON DEFAULT NULL, new_value JSON DEFAULT NULL, changeset_id INT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, INDEX idx_admin_audit_entity (entity_type, entity_id, occurred_at), INDEX idx_admin_audit_user (user_id, occurred_at), INDEX idx_admin_audit_changeset (changeset_id), INDEX idx_admin_audit_source_entity (source, entity_type, entity_id, occurred_at), INDEX idx_admin_audit_occurred (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_entry');
    }
}

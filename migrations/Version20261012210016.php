<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012210016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Персональні API-токени адмінки admin_api_token (E15 PR-2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_api_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(100) NOT NULL, token_hash CHAR(64) NOT NULL, prefix VARCHAR(16) NOT NULL, scopes JSON NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, revoked_by_id INT DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, ip_allowlist JSON DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_admin_api_token_user (user_id, created_at), UNIQUE INDEX uniq_admin_api_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE admin_api_token ADD CONSTRAINT FK_ADMIN_API_TOKEN_USER FOREIGN KEY (user_id) REFERENCES security_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_api_token DROP FOREIGN KEY FK_ADMIN_API_TOKEN_USER');
        $this->addSql('DROP TABLE admin_api_token');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** E12 PR-1: покупці сайту (customer) і одноразові токени листів (customer_auth_token). */
final class Version20261008054540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E12 PR-1: customer, customer_auth_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, email_verified_at DATETIME DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, name VARCHAR(120) NOT NULL, phone VARCHAR(32) DEFAULT NULL, locale VARCHAR(12) NOT NULL, active TINYINT NOT NULL, auth_version INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, customer_group_id INT DEFAULT NULL, INDEX idx_customer_unverified (email_verified_at, created_at), UNIQUE INDEX uniq_customer_email (email), INDEX IDX_81398E09D2919A68 (customer_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_auth_token (id INT AUTO_INCREMENT NOT NULL, purpose VARCHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, customer_id INT NOT NULL, INDEX idx_customer_auth_token_expires (expires_at), UNIQUE INDEX uniq_customer_auth_token_hash (token_hash), INDEX IDX_6EDCF4CC9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E09D2919A68 FOREIGN KEY (customer_group_id) REFERENCES customer_group (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_auth_token ADD CONSTRAINT FK_6EDCF4CC9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP FOREIGN KEY FK_81398E09D2919A68');
        $this->addSql('ALTER TABLE customer_auth_token DROP FOREIGN KEY FK_6EDCF4CC9395C3F3');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE customer_auth_token');
    }
}

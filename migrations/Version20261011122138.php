<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E12 PR-6: адреси доставки (customer_address), журнал згод append-only (customer_consent), видалення акаунта
 * (customer.email nullable, deleted_at), зміна email через токен на нову адресу (customer_auth_token.target_email).
 */
final class Version20261011122138 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Кабінет: адреси доставки, журнал згод, видалення акаунта, зміна email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_address (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(60) DEFAULT NULL, recipient_name VARCHAR(120) NOT NULL, recipient_phone VARCHAR(20) NOT NULL, shipping_method VARCHAR(16) NOT NULL, city VARCHAR(120) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, shipping_point JSON DEFAULT NULL, is_default TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id INT NOT NULL, INDEX idx_customer_address_customer (customer_id, is_default), INDEX IDX_1193CB3F9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_consent (id INT AUTO_INCREMENT NOT NULL, purpose VARCHAR(32) NOT NULL, granted TINYINT NOT NULL, policy_version VARCHAR(32) NOT NULL, policy_hash CHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, ip_hmac VARCHAR(32) DEFAULT NULL, occurred_at DATETIME NOT NULL, customer_id INT NOT NULL, INDEX idx_customer_consent_customer (customer_id, purpose, id), INDEX IDX_7FCFACAE9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_address ADD CONSTRAINT FK_1193CB3F9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_consent ADD CONSTRAINT FK_7FCFACAE9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer ADD deleted_at DATETIME DEFAULT NULL, CHANGE email email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_auth_token ADD target_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_address DROP FOREIGN KEY FK_1193CB3F9395C3F3');
        $this->addSql('ALTER TABLE customer_consent DROP FOREIGN KEY FK_7FCFACAE9395C3F3');
        $this->addSql('DROP TABLE customer_address');
        $this->addSql('DROP TABLE customer_consent');
        $this->addSql('ALTER TABLE customer DROP deleted_at, CHANGE email email VARCHAR(180) NOT NULL');
        $this->addSql('ALTER TABLE customer_auth_token DROP target_email');
    }
}

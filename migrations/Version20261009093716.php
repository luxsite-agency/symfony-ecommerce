<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E13 PR-5 «Оптовики: заявка і компанія» (консиліум 07.10.2026, П2): company (реквізити, унікальний ЄДРПОУ/РНОКПП),
 * company_member (покупець у компанії; у v1 один на компанію й одна компанія на покупця), wholesale_application
 * (снепшот реквізитів, стан, хто й коли вирішив, група; (customer_id, pending_key) тримає «одну активну заявку» в БД).
 */
final class Version20261009093716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E13: company, company_member, wholesale_application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, tax_id VARCHAR(10) NOT NULL, vat_payer TINYINT NOT NULL, contact_name VARCHAR(120) NOT NULL, contact_phone VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_company_tax_id (tax_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE company_member (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, company_id INT NOT NULL, customer_id INT NOT NULL, UNIQUE INDEX uniq_company_member_customer (customer_id), UNIQUE INDEX uniq_company_member_pair (company_id, customer_id), INDEX IDX_4D7B9E0D979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wholesale_application (id INT AUTO_INCREMENT NOT NULL, company_name VARCHAR(255) NOT NULL, tax_id VARCHAR(10) NOT NULL, vat_payer TINYINT NOT NULL, contact_name VARCHAR(120) NOT NULL, contact_phone VARCHAR(32) DEFAULT NULL, note VARCHAR(1000) DEFAULT NULL, status VARCHAR(16) NOT NULL, pending_key INT DEFAULT NULL, created_at DATETIME NOT NULL, reviewed_by_email VARCHAR(180) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, review_comment VARCHAR(1000) DEFAULT NULL, customer_id INT NOT NULL, reviewed_by_id INT DEFAULT NULL, assigned_group_id INT DEFAULT NULL, company_id INT DEFAULT NULL, INDEX idx_wholesale_application_status (status, created_at), UNIQUE INDEX uniq_wholesale_application_pending (customer_id, pending_key), INDEX IDX_DF375E6A9395C3F3 (customer_id), INDEX IDX_DF375E6AFC6B21F1 (reviewed_by_id), INDEX IDX_DF375E6A8359DF4E (assigned_group_id), INDEX IDX_DF375E6A979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE company_member ADD CONSTRAINT FK_4D7B9E0D979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE company_member ADD CONSTRAINT FK_4D7B9E0D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wholesale_application ADD CONSTRAINT FK_DF375E6A9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wholesale_application ADD CONSTRAINT FK_DF375E6AFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES security_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wholesale_application ADD CONSTRAINT FK_DF375E6A8359DF4E FOREIGN KEY (assigned_group_id) REFERENCES customer_group (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wholesale_application ADD CONSTRAINT FK_DF375E6A979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company_member DROP FOREIGN KEY FK_4D7B9E0D979B1AD6');
        $this->addSql('ALTER TABLE company_member DROP FOREIGN KEY FK_4D7B9E0D9395C3F3');
        $this->addSql('ALTER TABLE wholesale_application DROP FOREIGN KEY FK_DF375E6A9395C3F3');
        $this->addSql('ALTER TABLE wholesale_application DROP FOREIGN KEY FK_DF375E6AFC6B21F1');
        $this->addSql('ALTER TABLE wholesale_application DROP FOREIGN KEY FK_DF375E6A8359DF4E');
        $this->addSql('ALTER TABLE wholesale_application DROP FOREIGN KEY FK_DF375E6A979B1AD6');
        $this->addSql('DROP TABLE wholesale_application');
        $this->addSql('DROP TABLE company_member');
        $this->addSql('DROP TABLE company');
    }
}

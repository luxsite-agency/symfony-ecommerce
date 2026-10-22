<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Консиліум безпеки 10.10, п. 8: токени вебхука CRM після ротації (crm:webhook:rotate-token), sha256, перехідні 24 год. */
final class Version20261010205819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'crm_webhook_token: ротація токена вебхука CRM';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crm_webhook_token (id INT AUTO_INCREMENT NOT NULL, token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_crm_webhook_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crm_webhook_token');
    }
}

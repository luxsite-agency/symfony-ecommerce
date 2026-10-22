<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблиця doctrine-транспорту Messenger (outbox, ADR D7). Транспорт з auto_setup=0, тому схему створює міграція, а не
 * перший messenger:consume (консиліум PR #6 п. 6). DDL — як у symfony/doctrine-messenger (Connection::addTableToSchema).
 */
final class Version20260927214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messenger: таблиця messenger_messages для транспорту doctrine:// (outbox, auto_setup=0)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}

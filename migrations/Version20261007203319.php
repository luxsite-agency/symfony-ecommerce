<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E12 PR-0: сесії в MySQL (PdoSessionHandler, framework.session.handler_id = DATABASE_URL). Таблиця поза ORM:
 * doctrine.dbal.schema_filter її виключає, тож schema:validate і make:migration її не бачать і не пропонують DROP;
 * створює й змінює її лише ця (і наступні ручні) міграції. DDL — як у PdoSessionHandler::configureSchema() для MySQL.
 */
final class Version20261007203319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E12 PR-0: таблиця sessions для PdoSessionHandler';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL, sess_data LONGBLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}

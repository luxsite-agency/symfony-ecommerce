<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928093440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф3 опції (ADR Rev 9 Q2): OptionGroup.inputType select|number і поля числової опції (межі, крок, одиниця, правило, ставка, поріг)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_option_group ADD input_type VARCHAR(16) DEFAULT \'select\' NOT NULL, ADD number_min NUMERIC(16, 4) DEFAULT NULL, ADD number_max NUMERIC(16, 4) DEFAULT NULL, ADD number_step NUMERIC(16, 4) DEFAULT NULL, ADD number_unit VARCHAR(16) DEFAULT NULL, ADD number_rule VARCHAR(16) DEFAULT NULL, ADD number_rate_minor INT DEFAULT NULL, ADD number_threshold NUMERIC(16, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_option_group DROP input_type, DROP number_min, DROP number_max, DROP number_step, DROP number_unit, DROP number_rule, DROP number_rate_minor, DROP number_threshold');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012210123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Пакети змін admin_changeset (E15 PR-3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_changeset (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, author VARCHAR(180) NOT NULL, token_id INT DEFAULT NULL, source VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, operations JSON NOT NULL, diff JSON NOT NULL, warnings JSON NOT NULL, risk_flags JSON NOT NULL, applied_by_name VARCHAR(180) DEFAULT NULL, applied_at DATETIME DEFAULT NULL, rejected_by_name VARCHAR(180) DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, risk_confirmed_by_name VARCHAR(180) DEFAULT NULL, risk_confirmed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT DEFAULT NULL, applied_by_id INT DEFAULT NULL, rejected_by_id INT DEFAULT NULL, risk_confirmed_by_id INT DEFAULT NULL, revert_of_id INT DEFAULT NULL, INDEX idx_admin_changeset_status (status, created_at), INDEX idx_admin_changeset_user (user_id, created_at), INDEX IDX_489E6E2AA76ED395 (user_id), INDEX IDX_489E6E2A4B8DEE4D (applied_by_id), INDEX IDX_489E6E2ACBF05FC9 (rejected_by_id), INDEX IDX_489E6E2AA4B22F02 (risk_confirmed_by_id), INDEX IDX_489E6E2AE8215EDC (revert_of_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_changeset ADD CONSTRAINT FK_489E6E2AA76ED395 FOREIGN KEY (user_id) REFERENCES security_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE admin_changeset ADD CONSTRAINT FK_489E6E2A4B8DEE4D FOREIGN KEY (applied_by_id) REFERENCES security_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE admin_changeset ADD CONSTRAINT FK_489E6E2ACBF05FC9 FOREIGN KEY (rejected_by_id) REFERENCES security_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE admin_changeset ADD CONSTRAINT FK_489E6E2AA4B22F02 FOREIGN KEY (risk_confirmed_by_id) REFERENCES security_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE admin_changeset ADD CONSTRAINT FK_489E6E2AE8215EDC FOREIGN KEY (revert_of_id) REFERENCES admin_changeset (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_changeset DROP FOREIGN KEY FK_489E6E2AA76ED395');
        $this->addSql('ALTER TABLE admin_changeset DROP FOREIGN KEY FK_489E6E2A4B8DEE4D');
        $this->addSql('ALTER TABLE admin_changeset DROP FOREIGN KEY FK_489E6E2ACBF05FC9');
        $this->addSql('ALTER TABLE admin_changeset DROP FOREIGN KEY FK_489E6E2AA4B22F02');
        $this->addSql('ALTER TABLE admin_changeset DROP FOREIGN KEY FK_489E6E2AE8215EDC');
        $this->addSql('DROP TABLE admin_changeset');
    }
}

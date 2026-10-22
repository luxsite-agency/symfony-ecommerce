<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** E15 BYOK (PR-1b): журнал використання AI — хто, що, модель, токени, вартість; текстів запитів тут нема. */
final class Version20261014060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ai_usage: журнал викликів AI на ключі клієнта';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (id BIGINT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, user_id INT DEFAULT NULL, actor VARCHAR(180) DEFAULT NULL, feature VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, model VARCHAR(128) DEFAULT NULL, entity_type VARCHAR(32) DEFAULT NULL, entity_id INT DEFAULT NULL, changeset_id INT DEFAULT NULL, prompt_tokens INT NOT NULL, completion_tokens INT NOT NULL, cost NUMERIC(12, 6) DEFAULT NULL, duration_ms INT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, INDEX idx_ai_usage_occurred (occurred_at), INDEX idx_ai_usage_user (user_id, occurred_at), INDEX idx_ai_usage_entity (entity_type, entity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_usage');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Приймач зовнішньої контент-панелі: запис блогу за group_id панелі (content_post.studio_group_id), обкладинка від панелі
 * (studio_cover_id — ручну не перетираємо), FAQ, «Читайте також» і підпис під обкладинкою per-locale.
 */
final class Version20260928192822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Приймач зовнішньої контент-панелі: content_post.studio_group_id/studio_cover_id/studio_synced_at, content_post_translation.faq/related/cover_caption';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_post ADD studio_group_id VARCHAR(36) DEFAULT NULL, ADD studio_synced_at DATETIME DEFAULT NULL, ADD studio_cover_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_post ADD CONSTRAINT FK_97E88148EE2597EB FOREIGN KEY (studio_cover_id) REFERENCES catalog_media (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_post_studio_group ON content_post (studio_group_id)');
        $this->addSql('CREATE INDEX IDX_97E88148EE2597EB ON content_post (studio_cover_id)');
        $this->addSql('ALTER TABLE content_post_translation ADD faq JSON DEFAULT NULL, ADD related JSON DEFAULT NULL, ADD cover_caption VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_post DROP FOREIGN KEY FK_97E88148EE2597EB');
        $this->addSql('DROP INDEX uniq_content_post_studio_group ON content_post');
        $this->addSql('DROP INDEX IDX_97E88148EE2597EB ON content_post');
        $this->addSql('ALTER TABLE content_post DROP studio_group_id, DROP studio_synced_at, DROP studio_cover_id');
        $this->addSql('ALTER TABLE content_post_translation DROP faq, DROP related, DROP cover_caption');
    }
}

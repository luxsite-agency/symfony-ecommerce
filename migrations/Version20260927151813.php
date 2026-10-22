<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927151813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф0: контент — сторінки (content_page), рубрики й записи блогу (content_post_category, content_post) з перекладами';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE content_page (id INT AUTO_INCREMENT NOT NULL, published TINYINT NOT NULL, position INT NOT NULL, show_in_header TINYINT NOT NULL, show_in_footer TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_content_page_nav (published, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_page_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(190) DEFAULT NULL, body LONGTEXT DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description VARCHAR(500) DEFAULT NULL, page_id INT NOT NULL, UNIQUE INDEX uniq_content_page_translation_locale (page_id, locale), UNIQUE INDEX uniq_content_page_translation_slug (locale, slug), INDEX IDX_1BE96AEFC4663E4 (page_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_post (id INT AUTO_INCREMENT NOT NULL, published_at DATETIME DEFAULT NULL, author_name VARCHAR(120) DEFAULT NULL, tags VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, category_id INT DEFAULT NULL, cover_id INT DEFAULT NULL, INDEX idx_content_post_published (published_at), INDEX IDX_97E8814812469DE2 (category_id), INDEX IDX_97E88148922726E9 (cover_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_post_category (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_content_post_category_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_post_category_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(190) DEFAULT NULL, category_id INT NOT NULL, UNIQUE INDEX uniq_content_post_category_translation_locale (category_id, locale), UNIQUE INDEX uniq_content_post_category_translation_slug (locale, slug), INDEX IDX_F86C59C812469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_post_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(190) DEFAULT NULL, excerpt LONGTEXT DEFAULT NULL, body LONGTEXT DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description VARCHAR(500) DEFAULT NULL, post_id INT NOT NULL, UNIQUE INDEX uniq_content_post_translation_locale (post_id, locale), UNIQUE INDEX uniq_content_post_translation_slug (locale, slug), INDEX IDX_E015BEB24B89032C (post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE content_page_translation ADD CONSTRAINT FK_1BE96AEFC4663E4 FOREIGN KEY (page_id) REFERENCES content_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_post ADD CONSTRAINT FK_97E8814812469DE2 FOREIGN KEY (category_id) REFERENCES content_post_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE content_post ADD CONSTRAINT FK_97E88148922726E9 FOREIGN KEY (cover_id) REFERENCES catalog_media (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE content_post_category_translation ADD CONSTRAINT FK_F86C59C812469DE2 FOREIGN KEY (category_id) REFERENCES content_post_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_post_translation ADD CONSTRAINT FK_E015BEB24B89032C FOREIGN KEY (post_id) REFERENCES content_post (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE content_page_translation DROP FOREIGN KEY FK_1BE96AEFC4663E4');
        $this->addSql('ALTER TABLE content_post DROP FOREIGN KEY FK_97E8814812469DE2');
        $this->addSql('ALTER TABLE content_post DROP FOREIGN KEY FK_97E88148922726E9');
        $this->addSql('ALTER TABLE content_post_category_translation DROP FOREIGN KEY FK_F86C59C812469DE2');
        $this->addSql('ALTER TABLE content_post_translation DROP FOREIGN KEY FK_E015BEB24B89032C');
        $this->addSql('DROP TABLE content_page');
        $this->addSql('DROP TABLE content_page_translation');
        $this->addSql('DROP TABLE content_post');
        $this->addSql('DROP TABLE content_post_category');
        $this->addSql('DROP TABLE content_post_category_translation');
        $this->addSql('DROP TABLE content_post_translation');
    }
}

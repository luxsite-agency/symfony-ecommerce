<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Рішення (Solution, docs/solutions.md): рішення з перекладами, групи, варіанти-товари з перекладами переваг, «інші рішення».
 * Лише нові таблиці — зворотно сумісно; down() прибирає їх.
 */
final class Version20261020105831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Рішення: content_solution*, групи, варіанти (товари), інші рішення';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_solution (id INT AUTO_INCREMENT NOT NULL, published TINYINT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, group_id INT DEFAULT NULL, cover_id INT DEFAULT NULL, INDEX idx_content_solution_list (published, position), INDEX IDX_CD84C334FE54D947 (group_id), INDEX IDX_CD84C334922726E9 (cover_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_related (solution_id INT NOT NULL, related_id INT NOT NULL, INDEX IDX_7CC933D81C0BE183 (solution_id), INDEX IDX_7CC933D84162C001 (related_id), PRIMARY KEY (solution_id, related_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_group (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_group_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, title VARCHAR(255) NOT NULL, group_id INT NOT NULL, UNIQUE INDEX uniq_content_solution_group_translation_locale (group_id, locale), INDEX IDX_61D77241FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_option (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, solution_id INT NOT NULL, product_id INT NOT NULL, INDEX idx_content_solution_option_product (product_id), UNIQUE INDEX uniq_content_solution_option_product (solution_id, product_id), INDEX IDX_A8A42AE11C0BE183 (solution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_option_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, pros JSON DEFAULT NULL, note LONGTEXT DEFAULT NULL, option_id INT NOT NULL, UNIQUE INDEX uniq_content_solution_option_translation_locale (option_id, locale), INDEX IDX_A218E51AA7C41D6F (option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_solution_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(190) DEFAULT NULL, intro LONGTEXT DEFAULT NULL, task LONGTEXT DEFAULT NULL, body LONGTEXT DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description VARCHAR(500) DEFAULT NULL, loads JSON DEFAULT NULL, industries JSON DEFAULT NULL, faq JSON DEFAULT NULL, solution_id INT NOT NULL, UNIQUE INDEX uniq_content_solution_translation_locale (solution_id, locale), UNIQUE INDEX uniq_content_solution_translation_slug (locale, slug), INDEX IDX_63D6FDFF1C0BE183 (solution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE content_solution ADD CONSTRAINT FK_CD84C334FE54D947 FOREIGN KEY (group_id) REFERENCES content_solution_group (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE content_solution ADD CONSTRAINT FK_CD84C334922726E9 FOREIGN KEY (cover_id) REFERENCES catalog_media (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE content_solution_related ADD CONSTRAINT FK_7CC933D81C0BE183 FOREIGN KEY (solution_id) REFERENCES content_solution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_related ADD CONSTRAINT FK_7CC933D84162C001 FOREIGN KEY (related_id) REFERENCES content_solution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_group_translation ADD CONSTRAINT FK_61D77241FE54D947 FOREIGN KEY (group_id) REFERENCES content_solution_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_option ADD CONSTRAINT FK_A8A42AE11C0BE183 FOREIGN KEY (solution_id) REFERENCES content_solution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_option ADD CONSTRAINT FK_A8A42AE14584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_option_translation ADD CONSTRAINT FK_A218E51AA7C41D6F FOREIGN KEY (option_id) REFERENCES content_solution_option (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_solution_translation ADD CONSTRAINT FK_63D6FDFF1C0BE183 FOREIGN KEY (solution_id) REFERENCES content_solution (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_solution DROP FOREIGN KEY FK_CD84C334FE54D947');
        $this->addSql('ALTER TABLE content_solution DROP FOREIGN KEY FK_CD84C334922726E9');
        $this->addSql('ALTER TABLE content_solution_related DROP FOREIGN KEY FK_7CC933D81C0BE183');
        $this->addSql('ALTER TABLE content_solution_related DROP FOREIGN KEY FK_7CC933D84162C001');
        $this->addSql('ALTER TABLE content_solution_group_translation DROP FOREIGN KEY FK_61D77241FE54D947');
        $this->addSql('ALTER TABLE content_solution_option DROP FOREIGN KEY FK_A8A42AE11C0BE183');
        $this->addSql('ALTER TABLE content_solution_option DROP FOREIGN KEY FK_A8A42AE14584665A');
        $this->addSql('ALTER TABLE content_solution_option_translation DROP FOREIGN KEY FK_A218E51AA7C41D6F');
        $this->addSql('ALTER TABLE content_solution_translation DROP FOREIGN KEY FK_63D6FDFF1C0BE183');
        $this->addSql('DROP TABLE content_solution');
        $this->addSql('DROP TABLE content_solution_related');
        $this->addSql('DROP TABLE content_solution_group');
        $this->addSql('DROP TABLE content_solution_group_translation');
        $this->addSql('DROP TABLE content_solution_option');
        $this->addSql('DROP TABLE content_solution_option_translation');
        $this->addSql('DROP TABLE content_solution_translation');
    }
}

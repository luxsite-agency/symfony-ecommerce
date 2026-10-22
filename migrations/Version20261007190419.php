<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Епік E3: менеджер редиректів (seo_redirect — джерело унікальне, бінарне порівняння; target_path — індекс злиття
 * ланцюгів) і журнал 404 сайту (seo_not_found — агрегат за шляхом, без персональних даних).
 */
final class Version20261007190419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E3: seo_redirect (менеджер редиректів, авто-301) і seo_not_found (журнал 404)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE seo_not_found (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, path VARCHAR(768) NOT NULL COLLATE `utf8mb4_bin`, hits INT UNSIGNED DEFAULT 1 NOT NULL, first_seen_at DATETIME NOT NULL, last_hit_at DATETIME NOT NULL, referer VARCHAR(500) DEFAULT NULL, INDEX idx_seo_not_found_last_hit (last_hit_at), UNIQUE INDEX uniq_seo_not_found_path (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seo_redirect (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(768) NOT NULL COLLATE `utf8mb4_bin`, target VARCHAR(2048) DEFAULT NULL, target_path VARCHAR(768) DEFAULT NULL COLLATE `utf8mb4_bin`, code SMALLINT NOT NULL, kind VARCHAR(8) NOT NULL, hits INT UNSIGNED DEFAULT 0 NOT NULL, last_hit_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_seo_redirect_target_path (target_path), UNIQUE INDEX uniq_seo_redirect_source (source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE seo_not_found');
        $this->addSql('DROP TABLE seo_redirect');
    }
}

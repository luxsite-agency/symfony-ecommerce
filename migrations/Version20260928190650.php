<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Адреси каталогу без /c/ і /p/ (власник, 2026-09-28): категорія — /{шлях за деревом}/. Повний шлях цією мовою —
 * catalog_category_translation.path, унікальний у межах мови; слаг категорії більше не унікальний глобально (лише серед
 * сестер — через шлях). Наявні шляхи заповнюються тут же рекурсивним CTE (деплой з даними), далі їх веде CategoryPaths.
 */
final class Version20260928190650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'URL без /c/ і /p/: catalog_category_translation.path (повний шлях за деревом), UNIQUE(locale, path) замість UNIQUE(locale, slug)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category_translation ADD path VARCHAR(700) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE catalog_category_translation t
            JOIN (
              WITH RECURSIVE p AS (
                SELECT c.id, ct.locale, CAST(ct.slug AS CHAR(700)) AS path
                FROM catalog_category c JOIN catalog_category_translation ct ON ct.category_id = c.id
                WHERE c.parent_id IS NULL AND ct.slug IS NOT NULL
                UNION ALL
                SELECT c.id, ct.locale, CAST(CONCAT(p.path, '/', ct.slug) AS CHAR(700))
                FROM catalog_category c
                JOIN p ON c.parent_id = p.id
                JOIN catalog_category_translation ct ON ct.category_id = c.id AND ct.locale = p.locale
                WHERE ct.slug IS NOT NULL
              )
              SELECT id, locale, path FROM p
            ) x ON x.id = t.category_id AND x.locale = t.locale
            SET t.path = x.path
        SQL);
        $this->addSql('ALTER TABLE catalog_category_translation DROP INDEX uniq_catalog_category_translation_slug, ADD INDEX idx_catalog_category_translation_slug (locale, slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_catalog_category_translation_path ON catalog_category_translation (locale, path)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_catalog_category_translation_path ON catalog_category_translation');
        $this->addSql('ALTER TABLE catalog_category_translation DROP INDEX idx_catalog_category_translation_slug, ADD UNIQUE INDEX uniq_catalog_category_translation_slug (locale, slug)');
        $this->addSql('ALTER TABLE catalog_category_translation DROP path');
    }
}

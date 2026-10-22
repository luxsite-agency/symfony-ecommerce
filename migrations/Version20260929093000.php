<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** SEO п. 10: окремий H1 сторінки категорії (порожній — name). */
final class Version20260929093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Категорії: catalog_category_translation.h1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category_translation ADD h1 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category_translation DROP h1');
    }
}

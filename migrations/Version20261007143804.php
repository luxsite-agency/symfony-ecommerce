<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Акції: явний прапорець «на весь каталог». Раніше порожні цілі означали весь каталог, і видалення бренду/категорії/товару
 * (каскад FK) робило акцію загальною. Наявним акціям без цілей прапорець ставиться, щоб їхня дія не змінилась.
 */
final class Version20261007143804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pricing_promotion.whole_catalog; наявні акції без цілей → whole_catalog = 1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pricing_promotion ADD whole_catalog TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE pricing_promotion p SET p.whole_catalog = 1
            WHERE NOT EXISTS (SELECT 1 FROM pricing_promotion_category c WHERE c.promotion_id = p.id)
              AND NOT EXISTS (SELECT 1 FROM pricing_promotion_brand b WHERE b.promotion_id = p.id)
              AND NOT EXISTS (SELECT 1 FROM pricing_promotion_product r WHERE r.promotion_id = p.id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pricing_promotion DROP whole_catalog');
    }
}

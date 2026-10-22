<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ф1: read-index фільтрів (ADR D3 / Rev 9). Три таблиці без Doctrine-сутностей і без FK — DDL канонічний з
 * consilium-2026-09-27-f0/critic.md; плюс службові catalog_filter_state (прапорець перебудови) і catalog_filter_dirty
 * (товари, змінені під час перебудови — перегратись після RENAME TABLE). Усі catalog_filter_* — поза schema_filter.
 * in_stock = listed AND policy=in_stock AND stock>0 (відхилення від DDL критика: інакше «в наявності» показувало б вимкнені товари).
 * Сутність: catalog_category_filter_attribute — які характеристики фільтруються в категорії (донор params_for_filter).
 */
final class Version20260927170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф1: read-index фільтрів catalog_filter_variant/_value/_product_category (+ state, dirty) і CategoryFilterAttribute';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_filter_variant (
              variant_id   INT UNSIGNED NOT NULL,
              product_id   INT UNSIGNED NOT NULL,
              brand_id     INT UNSIGNED NULL,
              price_minor  INT NOT NULL,
              price_mode   TINYINT UNSIGNED NOT NULL,
              listed       TINYINT(1) NOT NULL,
              purchasable  TINYINT(1) NOT NULL,
              in_stock     TINYINT(1) NOT NULL,
              PRIMARY KEY (variant_id),
              KEY idx_fv_product (product_id, variant_id),
              KEY idx_fv_purch_price (purchasable, price_minor, product_id, variant_id),
              KEY idx_fv_brand (brand_id, purchasable, price_minor)
            ) ENGINE=InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_filter_value (
              variant_id    INT UNSIGNED NOT NULL,
              product_id    INT UNSIGNED NOT NULL,
              attribute_id  INT UNSIGNED NOT NULL,
              option_id     INT UNSIGNED NOT NULL DEFAULT 0,
              number_value  DECIMAL(16,4) NULL,
              boolean_value TINYINT(1) NULL,
              PRIMARY KEY (variant_id, attribute_id, option_id),
              KEY idx_ff_option  (attribute_id, option_id, variant_id, product_id),
              KEY idx_ff_number  (attribute_id, number_value, variant_id, product_id),
              KEY idx_ff_boolean (attribute_id, boolean_value, variant_id, product_id),
              -- «рівно одне значення»; без імені: CREATE TABLE … LIKE при перебудові генерує <table>_chk_N, RENAME його переносить
              CHECK ((option_id <> 0) + (number_value IS NOT NULL) + (boolean_value IS NOT NULL) = 1)
            ) ENGINE=InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_filter_product_category (
              category_id INT UNSIGNED NOT NULL,
              product_id  INT UNSIGNED NOT NULL,
              PRIMARY KEY (category_id, product_id),
              -- регенерація/видалення товару: DELETE … WHERE product_id IN (…) без повного скану
              KEY idx_fpc_product (product_id)
            ) ENGINE=InnoDB
        SQL);
        // Прапорець «іде повна перебудова»: поки він 1, точкові оновлення й регенерації дублюються в dirty-список
        $this->addSql('CREATE TABLE catalog_filter_state (id TINYINT UNSIGNED NOT NULL, rebuilding TINYINT(1) NOT NULL DEFAULT 0, rebuild_started_at DATETIME NULL, rebuilt_at DATETIME NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
        $this->addSql('INSERT INTO catalog_filter_state (id, rebuilding) VALUES (1, 0)');
        $this->addSql('CREATE TABLE catalog_filter_dirty (product_id INT UNSIGNED NOT NULL, queued_at DATETIME(6) NOT NULL, PRIMARY KEY (product_id)) ENGINE=InnoDB');

        $this->addSql('CREATE TABLE catalog_category_filter_attribute (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, attribute_id INT NOT NULL, position INT NOT NULL, INDEX IDX_5A58B3C8B6E62EFA (attribute_id), UNIQUE INDEX uniq_catalog_category_filter_attribute (category_id, attribute_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE catalog_category_filter_attribute ADD CONSTRAINT FK_CCFA_CATEGORY FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_category_filter_attribute ADD CONSTRAINT FK_CCFA_ATTRIBUTE FOREIGN KEY (attribute_id) REFERENCES catalog_attribute (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_category_filter_attribute');
        $this->addSql('DROP TABLE catalog_filter_dirty');
        $this->addSql('DROP TABLE catalog_filter_state');
        $this->addSql('DROP TABLE catalog_filter_product_category');
        $this->addSql('DROP TABLE catalog_filter_value');
        $this->addSql('DROP TABLE catalog_filter_variant');
    }
}

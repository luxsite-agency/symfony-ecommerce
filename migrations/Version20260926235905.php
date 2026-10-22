<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ф0: схема каталогу з варіантами (ADR Rev 2–7). Адитивна міграція ядра (ADR D1).
 */
final class Version20260926235905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф0: схема каталогу — категорії, бренди, атрибути, товари з варіантами, опції, медіа, ціни, зовнішні id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_attribute (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(64) NOT NULL,
              type VARCHAR(16) NOT NULL,
              unit VARCHAR(16) DEFAULT NULL,
              filterable TINYINT NOT NULL,
              variant_axis TINYINT NOT NULL,
              position INT NOT NULL,
              UNIQUE INDEX uniq_catalog_attribute_code (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_attribute_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              name VARCHAR(255) NOT NULL,
              attribute_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_attribute_translation_locale (attribute_id, locale),
              INDEX IDX_38BB551AB6E62EFA (attribute_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_attribute_value (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(64) NOT NULL,
              position INT NOT NULL,
              attribute_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_attribute_value_code (attribute_id, code),
              INDEX IDX_806DB22DB6E62EFA (attribute_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_attribute_value_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              value VARCHAR(255) NOT NULL,
              attribute_value_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_attribute_value_translation_locale (attribute_value_id, locale),
              INDEX IDX_29E1654A65A22152 (attribute_value_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_brand (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              slug VARCHAR(190) NOT NULL,
              active TINYINT NOT NULL,
              UNIQUE INDEX uniq_catalog_brand_slug (slug),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_category (
              id INT AUTO_INCREMENT NOT NULL,
              position INT NOT NULL,
              active TINYINT NOT NULL,
              parent_id INT DEFAULT NULL,
              INDEX idx_catalog_category_parent_position (parent_id, position),
              INDEX IDX_349BC7DF727ACA70 (parent_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_category_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              name VARCHAR(255) NOT NULL,
              slug VARCHAR(190) DEFAULT NULL,
              description LONGTEXT DEFAULT NULL,
              meta_title VARCHAR(255) DEFAULT NULL,
              meta_description VARCHAR(500) DEFAULT NULL,
              category_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_category_translation_locale (category_id, locale),
              UNIQUE INDEX uniq_catalog_category_translation_slug (locale, slug),
              INDEX IDX_6F8863F12469DE2 (category_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_customer_group (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(64) NOT NULL,
              name VARCHAR(255) NOT NULL,
              position INT NOT NULL,
              UNIQUE INDEX uniq_catalog_customer_group_code (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_external_ref (
              id INT AUTO_INCREMENT NOT NULL,
              system_code VARCHAR(32) NOT NULL,
              entity_type VARCHAR(32) NOT NULL,
              entity_id INT NOT NULL,
              external_id VARCHAR(128) NOT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_catalog_external_ref_entity (entity_type, entity_id),
              UNIQUE INDEX uniq_catalog_external_ref_external (
                system_code, entity_type, external_id
              ),
              UNIQUE INDEX uniq_catalog_external_ref_entity (
                system_code, entity_type, entity_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_media (
              id INT AUTO_INCREMENT NOT NULL,
              path VARCHAR(255) NOT NULL,
              mime_type VARCHAR(100) NOT NULL,
              size_bytes INT NOT NULL,
              width INT DEFAULT NULL,
              height INT DEFAULT NULL,
              alt VARCHAR(255) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_catalog_media_path (path),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_option_group (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(64) NOT NULL,
              selection VARCHAR(16) NOT NULL,
              required TINYINT NOT NULL,
              position INT NOT NULL,
              product_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_option_group_code (product_id, code),
              INDEX IDX_6554C3634584665A (product_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_option_group_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              name VARCHAR(255) NOT NULL,
              group_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_option_group_translation_locale (group_id, locale),
              INDEX IDX_2B58E1C1FE54D947 (group_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_option_value (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(64) NOT NULL,
              kind VARCHAR(16) NOT NULL,
              modifier_type VARCHAR(16) DEFAULT NULL,
              amount_minor INT DEFAULT NULL,
              basis_points INT DEFAULT NULL,
              component_quantity NUMERIC(12, 3) DEFAULT NULL,
              selection_price_minor INT DEFAULT NULL,
              currency CHAR(3) DEFAULT NULL,
              is_default TINYINT NOT NULL,
              position INT NOT NULL,
              group_id INT NOT NULL,
              component_variant_id INT DEFAULT NULL,
              UNIQUE INDEX uniq_catalog_option_value_code (group_id, code),
              INDEX IDX_15E3DF92FE54D947 (group_id),
              INDEX IDX_15E3DF9227DB3151 (component_variant_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_option_value_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              label VARCHAR(255) NOT NULL,
              option_value_id INT NOT NULL,
              UNIQUE INDEX uniq_catalog_option_value_translation_locale (option_value_id, locale),
              INDEX IDX_CC154623D957CA06 (option_value_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_price_tier (
              id INT AUTO_INCREMENT NOT NULL,
              min_quantity NUMERIC(12, 3) NOT NULL,
              price_minor INT NOT NULL,
              currency CHAR(3) NOT NULL,
              variant_id INT NOT NULL,
              customer_group_id INT DEFAULT NULL,
              UNIQUE INDEX uniq_catalog_price_tier (
                variant_id, customer_group_id, min_quantity
              ),
              INDEX IDX_ECE697EB3B69A9AF (variant_id),
              INDEX IDX_ECE697EBD2919A68 (customer_group_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product (
              id INT AUTO_INCREMENT NOT NULL,
              kind VARCHAR(16) NOT NULL,
              price_mode VARCHAR(16) NOT NULL,
              active TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              source_updated_at DATETIME DEFAULT NULL,
              brand_id INT DEFAULT NULL,
              main_category_id INT DEFAULT NULL,
              INDEX idx_catalog_product_kind_active (kind, active),
              INDEX IDX_DCF8F98144F5D008 (brand_id),
              INDEX IDX_DCF8F981C6C55574 (main_category_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_category (
              product_id INT NOT NULL,
              category_id INT NOT NULL,
              INDEX IDX_8BEBEF264584665A (product_id),
              INDEX IDX_8BEBEF2612469DE2 (category_id),
              PRIMARY KEY (product_id, category_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_attribute_value (
              id INT AUTO_INCREMENT NOT NULL,
              number_value NUMERIC(16, 4) DEFAULT NULL,
              boolean_value TINYINT DEFAULT NULL,
              product_id INT NOT NULL,
              attribute_id INT NOT NULL,
              option_id INT DEFAULT NULL,
              option_key INT GENERATED ALWAYS AS (COALESCE(option_id, 0)) VIRTUAL NOT NULL,
              INDEX idx_catalog_product_attribute_value_attribute (attribute_id),
              UNIQUE INDEX uniq_catalog_product_attribute_value (
                product_id, attribute_id, option_key
              ),
              INDEX IDX_26B7F47F4584665A (product_id),
              INDEX IDX_26B7F47FA7C41D6F (option_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_media (
              id INT AUTO_INCREMENT NOT NULL,
              position INT NOT NULL,
              product_id INT NOT NULL,
              media_id INT NOT NULL,
              variant_id INT DEFAULT NULL,
              UNIQUE INDEX uniq_catalog_product_media (product_id, media_id),
              INDEX IDX_B79D4ADD4584665A (product_id),
              INDEX IDX_B79D4ADDEA9FDD75 (media_id),
              INDEX IDX_B79D4ADD3B69A9AF (variant_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_translation (
              id INT AUTO_INCREMENT NOT NULL,
              locale VARCHAR(12) NOT NULL,
              name VARCHAR(255) NOT NULL,
              slug VARCHAR(190) DEFAULT NULL,
              short_description LONGTEXT DEFAULT NULL,
              description LONGTEXT DEFAULT NULL,
              meta_title VARCHAR(255) DEFAULT NULL,
              meta_description VARCHAR(500) DEFAULT NULL,
              product_id INT NOT NULL,
              FULLTEXT INDEX ft_catalog_product_translation_name (name),
              UNIQUE INDEX uniq_catalog_product_translation_locale (product_id, locale),
              UNIQUE INDEX uniq_catalog_product_translation_slug (locale, slug),
              INDEX IDX_1290F3B74584665A (product_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_variant (
              id INT AUTO_INCREMENT NOT NULL,
              sku VARCHAR(64) NOT NULL,
              external_id VARCHAR(128) DEFAULT NULL,
              barcode VARCHAR(64) DEFAULT NULL,
              price_minor INT NOT NULL,
              old_price_minor INT DEFAULT NULL,
              currency CHAR(3) NOT NULL,
              stock NUMERIC(12, 3) NOT NULL,
              stock_policy VARCHAR(16) NOT NULL,
              lead_time_days SMALLINT DEFAULT NULL,
              unit VARCHAR(8) NOT NULL,
              min_quantity NUMERIC(12, 3) NOT NULL,
              quantity_step NUMERIC(12, 3) NOT NULL,
              pack_quantity NUMERIC(12, 3) DEFAULT NULL,
              position INT NOT NULL,
              active TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              product_id INT NOT NULL,
              INDEX idx_catalog_product_variant_barcode (barcode),
              INDEX idx_catalog_product_variant_product_position (product_id, position),
              UNIQUE INDEX uniq_catalog_product_variant_sku (sku),
              UNIQUE INDEX uniq_catalog_product_variant_external_id (external_id),
              INDEX IDX_5EB8ADB24584665A (product_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_variant_axis_value (
              id INT AUTO_INCREMENT NOT NULL,
              variant_id INT NOT NULL,
              attribute_id INT NOT NULL,
              attribute_value_id INT NOT NULL,
              INDEX idx_catalog_product_variant_axis_value (attribute_value_id),
              UNIQUE INDEX uniq_catalog_product_variant_axis (variant_id, attribute_id),
              INDEX IDX_296737F23B69A9AF (variant_id),
              INDEX IDX_296737F2B6E62EFA (attribute_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_attribute_translation
            ADD
              CONSTRAINT FK_38BB551AB6E62EFA FOREIGN KEY (attribute_id) REFERENCES catalog_attribute (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_attribute_value
            ADD
              CONSTRAINT FK_806DB22DB6E62EFA FOREIGN KEY (attribute_id) REFERENCES catalog_attribute (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_attribute_value_translation
            ADD
              CONSTRAINT FK_29E1654A65A22152 FOREIGN KEY (attribute_value_id) REFERENCES catalog_attribute_value (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_category
            ADD
              CONSTRAINT FK_349BC7DF727ACA70 FOREIGN KEY (parent_id) REFERENCES catalog_category (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_category_translation
            ADD
              CONSTRAINT FK_6F8863F12469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_option_group
            ADD
              CONSTRAINT FK_6554C3634584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_option_group_translation
            ADD
              CONSTRAINT FK_2B58E1C1FE54D947 FOREIGN KEY (group_id) REFERENCES catalog_option_group (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_option_value
            ADD
              CONSTRAINT FK_15E3DF92FE54D947 FOREIGN KEY (group_id) REFERENCES catalog_option_group (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_option_value
            ADD
              CONSTRAINT FK_15E3DF9227DB3151 FOREIGN KEY (component_variant_id) REFERENCES catalog_product_variant (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_option_value_translation
            ADD
              CONSTRAINT FK_CC154623D957CA06 FOREIGN KEY (option_value_id) REFERENCES catalog_option_value (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_price_tier
            ADD
              CONSTRAINT FK_ECE697EB3B69A9AF FOREIGN KEY (variant_id) REFERENCES catalog_product_variant (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_price_tier
            ADD
              CONSTRAINT FK_ECE697EBD2919A68 FOREIGN KEY (customer_group_id) REFERENCES catalog_customer_group (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product
            ADD
              CONSTRAINT FK_DCF8F98144F5D008 FOREIGN KEY (brand_id) REFERENCES catalog_brand (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product
            ADD
              CONSTRAINT FK_DCF8F981C6C55574 FOREIGN KEY (main_category_id) REFERENCES catalog_category (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_category
            ADD
              CONSTRAINT FK_8BEBEF264584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_category
            ADD
              CONSTRAINT FK_8BEBEF2612469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_attribute_value
            ADD
              CONSTRAINT FK_26B7F47F4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_attribute_value
            ADD
              CONSTRAINT FK_26B7F47FB6E62EFA FOREIGN KEY (attribute_id) REFERENCES catalog_attribute (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_attribute_value
            ADD
              CONSTRAINT FK_26B7F47FA7C41D6F FOREIGN KEY (option_id) REFERENCES catalog_attribute_value (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_media
            ADD
              CONSTRAINT FK_B79D4ADD4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_media
            ADD
              CONSTRAINT FK_B79D4ADDEA9FDD75 FOREIGN KEY (media_id) REFERENCES catalog_media (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_media
            ADD
              CONSTRAINT FK_B79D4ADD3B69A9AF FOREIGN KEY (variant_id) REFERENCES catalog_product_variant (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_translation
            ADD
              CONSTRAINT FK_1290F3B74584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_variant
            ADD
              CONSTRAINT FK_5EB8ADB24584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_variant_axis_value
            ADD
              CONSTRAINT FK_296737F23B69A9AF FOREIGN KEY (variant_id) REFERENCES catalog_product_variant (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_variant_axis_value
            ADD
              CONSTRAINT FK_296737F2B6E62EFA FOREIGN KEY (attribute_id) REFERENCES catalog_attribute (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              catalog_product_variant_axis_value
            ADD
              CONSTRAINT FK_296737F265A22152 FOREIGN KEY (attribute_value_id) REFERENCES catalog_attribute_value (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_attribute_translation DROP FOREIGN KEY FK_38BB551AB6E62EFA');
        $this->addSql('ALTER TABLE catalog_attribute_value DROP FOREIGN KEY FK_806DB22DB6E62EFA');
        $this->addSql('ALTER TABLE catalog_attribute_value_translation DROP FOREIGN KEY FK_29E1654A65A22152');
        $this->addSql('ALTER TABLE catalog_category DROP FOREIGN KEY FK_349BC7DF727ACA70');
        $this->addSql('ALTER TABLE catalog_category_translation DROP FOREIGN KEY FK_6F8863F12469DE2');
        $this->addSql('ALTER TABLE catalog_option_group DROP FOREIGN KEY FK_6554C3634584665A');
        $this->addSql('ALTER TABLE catalog_option_group_translation DROP FOREIGN KEY FK_2B58E1C1FE54D947');
        $this->addSql('ALTER TABLE catalog_option_value DROP FOREIGN KEY FK_15E3DF92FE54D947');
        $this->addSql('ALTER TABLE catalog_option_value DROP FOREIGN KEY FK_15E3DF9227DB3151');
        $this->addSql('ALTER TABLE catalog_option_value_translation DROP FOREIGN KEY FK_CC154623D957CA06');
        $this->addSql('ALTER TABLE catalog_price_tier DROP FOREIGN KEY FK_ECE697EB3B69A9AF');
        $this->addSql('ALTER TABLE catalog_price_tier DROP FOREIGN KEY FK_ECE697EBD2919A68');
        $this->addSql('ALTER TABLE catalog_product DROP FOREIGN KEY FK_DCF8F98144F5D008');
        $this->addSql('ALTER TABLE catalog_product DROP FOREIGN KEY FK_DCF8F981C6C55574');
        $this->addSql('ALTER TABLE catalog_product_category DROP FOREIGN KEY FK_8BEBEF264584665A');
        $this->addSql('ALTER TABLE catalog_product_category DROP FOREIGN KEY FK_8BEBEF2612469DE2');
        $this->addSql('ALTER TABLE catalog_product_attribute_value DROP FOREIGN KEY FK_26B7F47F4584665A');
        $this->addSql('ALTER TABLE catalog_product_attribute_value DROP FOREIGN KEY FK_26B7F47FB6E62EFA');
        $this->addSql('ALTER TABLE catalog_product_attribute_value DROP FOREIGN KEY FK_26B7F47FA7C41D6F');
        $this->addSql('ALTER TABLE catalog_product_media DROP FOREIGN KEY FK_B79D4ADD4584665A');
        $this->addSql('ALTER TABLE catalog_product_media DROP FOREIGN KEY FK_B79D4ADDEA9FDD75');
        $this->addSql('ALTER TABLE catalog_product_media DROP FOREIGN KEY FK_B79D4ADD3B69A9AF');
        $this->addSql('ALTER TABLE catalog_product_translation DROP FOREIGN KEY FK_1290F3B74584665A');
        $this->addSql('ALTER TABLE catalog_product_variant DROP FOREIGN KEY FK_5EB8ADB24584665A');
        $this->addSql('ALTER TABLE catalog_product_variant_axis_value DROP FOREIGN KEY FK_296737F23B69A9AF');
        $this->addSql('ALTER TABLE catalog_product_variant_axis_value DROP FOREIGN KEY FK_296737F2B6E62EFA');
        $this->addSql('ALTER TABLE catalog_product_variant_axis_value DROP FOREIGN KEY FK_296737F265A22152');
        $this->addSql('DROP TABLE catalog_attribute');
        $this->addSql('DROP TABLE catalog_attribute_translation');
        $this->addSql('DROP TABLE catalog_attribute_value');
        $this->addSql('DROP TABLE catalog_attribute_value_translation');
        $this->addSql('DROP TABLE catalog_brand');
        $this->addSql('DROP TABLE catalog_category');
        $this->addSql('DROP TABLE catalog_category_translation');
        $this->addSql('DROP TABLE catalog_customer_group');
        $this->addSql('DROP TABLE catalog_external_ref');
        $this->addSql('DROP TABLE catalog_media');
        $this->addSql('DROP TABLE catalog_option_group');
        $this->addSql('DROP TABLE catalog_option_group_translation');
        $this->addSql('DROP TABLE catalog_option_value');
        $this->addSql('DROP TABLE catalog_option_value_translation');
        $this->addSql('DROP TABLE catalog_price_tier');
        $this->addSql('DROP TABLE catalog_product');
        $this->addSql('DROP TABLE catalog_product_category');
        $this->addSql('DROP TABLE catalog_product_attribute_value');
        $this->addSql('DROP TABLE catalog_product_media');
        $this->addSql('DROP TABLE catalog_product_translation');
        $this->addSql('DROP TABLE catalog_product_variant');
        $this->addSql('DROP TABLE catalog_product_variant_axis_value');
    }
}

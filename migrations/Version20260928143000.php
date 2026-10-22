<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ф3: акції (Promotion + цілі: категорії, бренди, товари) і снепшот знижки в рядку замовлення. */
final class Version20260928143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Акції: pricing_promotion (+category/brand/product), sales_order_item.list_price_minor / promotion_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pricing_promotion (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, active TINYINT NOT NULL, type VARCHAR(16) NOT NULL, value BIGINT NOT NULL, currency CHAR(3) NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, priority INT NOT NULL, stackable TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_pricing_promotion_active_dates (active, starts_at, ends_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pricing_promotion_category (promotion_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_5FE165FA139DF194 (promotion_id), INDEX IDX_5FE165FA12469DE2 (category_id), PRIMARY KEY (promotion_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pricing_promotion_brand (promotion_id INT NOT NULL, brand_id INT NOT NULL, INDEX IDX_1D4D0DB2139DF194 (promotion_id), INDEX IDX_1D4D0DB244F5D008 (brand_id), PRIMARY KEY (promotion_id, brand_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pricing_promotion_product (promotion_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_CD0B28D1139DF194 (promotion_id), INDEX IDX_CD0B28D14584665A (product_id), PRIMARY KEY (promotion_id, product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pricing_promotion_category ADD CONSTRAINT FK_5FE165FA139DF194 FOREIGN KEY (promotion_id) REFERENCES pricing_promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_category ADD CONSTRAINT FK_5FE165FA12469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_brand ADD CONSTRAINT FK_1D4D0DB2139DF194 FOREIGN KEY (promotion_id) REFERENCES pricing_promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_brand ADD CONSTRAINT FK_1D4D0DB244F5D008 FOREIGN KEY (brand_id) REFERENCES catalog_brand (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_product ADD CONSTRAINT FK_CD0B28D1139DF194 FOREIGN KEY (promotion_id) REFERENCES pricing_promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_product ADD CONSTRAINT FK_CD0B28D14584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order_item ADD list_price_minor BIGINT DEFAULT NULL, ADD promotion_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_item DROP list_price_minor, DROP promotion_name');
        $this->addSql('DROP TABLE pricing_promotion_category');
        $this->addSql('DROP TABLE pricing_promotion_brand');
        $this->addSql('DROP TABLE pricing_promotion_product');
        $this->addSql('DROP TABLE pricing_promotion');
    }
}

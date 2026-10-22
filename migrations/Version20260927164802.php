<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927164802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф2: замовлення — sales_order, sales_order_item, sales_order_status_history, лічильник номерів YYMM';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sales_order (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(20) NOT NULL, status VARCHAR(16) NOT NULL, payment_status VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, locale VARCHAR(12) NOT NULL, customer_name VARCHAR(120) NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(180) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, shipping_method VARCHAR(16) NOT NULL, shipping_city VARCHAR(120) DEFAULT NULL, shipping_address VARCHAR(255) DEFAULT NULL, payment_method VARCHAR(16) NOT NULL, currency CHAR(3) NOT NULL, items_total_minor INT NOT NULL, shipping_minor INT NOT NULL, total_minor INT NOT NULL, success_token VARCHAR(64) NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, placed_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX idx_sales_order_status_placed (status, placed_at), INDEX idx_sales_order_phone (phone), UNIQUE INDEX uniq_sales_order_number (number), UNIQUE INDEX uniq_sales_order_success_token (success_token), UNIQUE INDEX uniq_sales_order_idempotency (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_order_item (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, unit_price_minor INT NOT NULL, quantity NUMERIC(12, 3) NOT NULL, line_total_minor INT NOT NULL, reserved_stock NUMERIC(12, 3) NOT NULL, options_snapshot JSON DEFAULT NULL, position INT NOT NULL, order_id INT NOT NULL, variant_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, INDEX idx_sales_order_item_variant (variant_id), INDEX IDX_5DD6A8658D9F6D38 (order_id), INDEX IDX_5DD6A865727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_order_number_counter (period CHAR(4) NOT NULL, last INT NOT NULL, PRIMARY KEY (period)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_order_status_history (id INT AUTO_INCREMENT NOT NULL, from_status VARCHAR(16) DEFAULT NULL, to_status VARCHAR(16) NOT NULL, transition VARCHAR(32) NOT NULL, actor VARCHAR(180) NOT NULL, comment VARCHAR(500) DEFAULT NULL, at DATETIME NOT NULL, order_id INT NOT NULL, INDEX IDX_84EEA80F8D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sales_order_item ADD CONSTRAINT FK_5DD6A8658D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order_item ADD CONSTRAINT FK_5DD6A8653B69A9AF FOREIGN KEY (variant_id) REFERENCES catalog_product_variant (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sales_order_item ADD CONSTRAINT FK_5DD6A865727ACA70 FOREIGN KEY (parent_id) REFERENCES sales_order_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order_status_history ADD CONSTRAINT FK_84EEA80F8D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sales_order_item DROP FOREIGN KEY FK_5DD6A8658D9F6D38');
        $this->addSql('ALTER TABLE sales_order_item DROP FOREIGN KEY FK_5DD6A8653B69A9AF');
        $this->addSql('ALTER TABLE sales_order_item DROP FOREIGN KEY FK_5DD6A865727ACA70');
        $this->addSql('ALTER TABLE sales_order_status_history DROP FOREIGN KEY FK_84EEA80F8D9F6D38');
        $this->addSql('DROP TABLE sales_order');
        $this->addSql('DROP TABLE sales_order_item');
        $this->addSql('DROP TABLE sales_order_number_counter');
        $this->addSql('DROP TABLE sales_order_status_history');
    }
}

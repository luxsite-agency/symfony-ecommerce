<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E13 PR-4: замовлення знає покупця з токена (FK SET NULL, видалення акаунта лишає замовлення) і снепшот коду групи,
 * за якою PlaceOrder порахував ціни на момент оформлення.
 */
final class Version20261009094019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E13: sales_order.customer_id і снепшот групи customer_group_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD customer_group_code VARCHAR(64) DEFAULT NULL, ADD customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_order ADD CONSTRAINT FK_36D222E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_sales_order_customer ON sales_order (customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY FK_36D222E9395C3F3');
        $this->addSql('DROP INDEX idx_sales_order_customer ON sales_order');
        $this->addSql('ALTER TABLE sales_order DROP customer_group_code, DROP customer_id');
    }
}

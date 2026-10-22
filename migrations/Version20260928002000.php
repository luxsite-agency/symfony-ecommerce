<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Дашборд адмінки: індекси під агрегати — замовлення за датою, варіанти з низьким залишком. */
final class Version20260928002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Дашборд: індекси sales_order(placed_at) і catalog_product_variant(stock_policy, active, stock)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_sales_order_placed_at ON sales_order (placed_at)');
        $this->addSql('CREATE INDEX idx_catalog_product_variant_stock ON catalog_product_variant (stock_policy, active, stock)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sales_order_placed_at ON sales_order');
        $this->addSql('DROP INDEX idx_catalog_product_variant_stock ON catalog_product_variant');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011135952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Група покупців: мінімум замовлення (сума, кількість) і точні залишки (E13 PR-7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_group ADD min_order_amount_minor INT DEFAULT NULL, ADD min_order_quantity NUMERIC(12, 3) DEFAULT NULL, ADD exact_stock TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_group DROP min_order_amount_minor, DROP min_order_quantity, DROP exact_stock');
    }
}

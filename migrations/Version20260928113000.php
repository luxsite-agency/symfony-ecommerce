<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ф3 (D9): локальні довідники Нової пошти (міста, відділення/поштомати) і снепшот обраного відділення в замовленні. */
final class Version20260928113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Нова пошта: shipping_np_city, shipping_np_warehouse; sales_order.shipping_point';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shipping_np_city (ref CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL, name_ru VARCHAR(190) NOT NULL, area VARCHAR(120) NOT NULL, settlement_type VARCHAR(60) NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_shipping_np_city_name (name), INDEX idx_shipping_np_city_name_ru (name_ru), PRIMARY KEY (ref)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shipping_np_warehouse (ref CHAR(36) NOT NULL, city_ref CHAR(36) NOT NULL, number VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, name_ru VARCHAR(255) NOT NULL, short_address VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, active TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_shipping_np_warehouse_city (city_ref, active, type), PRIMARY KEY (ref)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sales_order ADD shipping_point JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP shipping_point');
        $this->addSql('DROP TABLE shipping_np_warehouse');
        $this->addSql('DROP TABLE shipping_np_city');
    }
}

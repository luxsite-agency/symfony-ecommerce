<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** E12 PR-2: прив'язка гостьових замовлень до покупця (claimed_at, claim_method) і індекс sales_order.email для пошуку за підтвердженою адресою (консиліум 07.10.2026, п. 11). */
final class Version20261011104626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Замовлення: журнал прив\'язки до покупця і індекс email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD claimed_at DATETIME DEFAULT NULL, ADD claim_method VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sales_order_email ON sales_order (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sales_order_email ON sales_order');
        $this->addSql('ALTER TABLE sales_order DROP claimed_at, DROP claim_method');
    }
}

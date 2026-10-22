<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Консиліум PR #6 (low): суми замовлення у BIGINT — INT переповнювався на 21 474 836,47 UAH. */
final class Version20260927231000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Замовлення: *_minor у sales_order і sales_order_item → BIGINT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order CHANGE items_total_minor items_total_minor BIGINT NOT NULL, CHANGE shipping_minor shipping_minor BIGINT NOT NULL, CHANGE total_minor total_minor BIGINT NOT NULL');
        $this->addSql('ALTER TABLE sales_order_item CHANGE unit_price_minor unit_price_minor BIGINT NOT NULL, CHANGE line_total_minor line_total_minor BIGINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order CHANGE items_total_minor items_total_minor INT NOT NULL, CHANGE shipping_minor shipping_minor INT NOT NULL, CHANGE total_minor total_minor INT NOT NULL');
        $this->addSql('ALTER TABLE sales_order_item CHANGE unit_price_minor unit_price_minor INT NOT NULL, CHANGE line_total_minor line_total_minor INT NOT NULL');
    }
}

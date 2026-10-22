<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Заявка без товару (/request): компанія на замовленні і вкладення покупців sales_order_attachment (файли — поза public/). */
final class Version20261020112510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order.company + sales_order_attachment (lead form /request)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sales_order_attachment (id INT AUTO_INCREMENT NOT NULL, original_name VARCHAR(200) NOT NULL, mime VARCHAR(64) NOT NULL, size INT NOT NULL, stored_name VARCHAR(32) NOT NULL, uploaded_at DATETIME NOT NULL, purged_at DATETIME DEFAULT NULL, order_id INT NOT NULL, UNIQUE INDEX UNIQ_A6F53FEF1185AF6A (stored_name), INDEX idx_order_attachment_uploaded (uploaded_at), INDEX IDX_A6F53FEF8D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sales_order_attachment ADD CONSTRAINT FK_A6F53FEF8D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order ADD company VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_attachment DROP FOREIGN KEY FK_A6F53FEF8D9F6D38');
        $this->addSql('DROP TABLE sales_order_attachment');
        $this->addSql('ALTER TABLE sales_order DROP company');
    }
}

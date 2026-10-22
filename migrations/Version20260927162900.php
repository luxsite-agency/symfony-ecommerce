<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927162900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф2: DB-кошик — cart (токен-хеш, валюта, TTL) і cart_item (варіант × кількість, снепшот опцій)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cart (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, currency CHAR(3) NOT NULL, customer_id INT DEFAULT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_cart_expires (expires_at), UNIQUE INDEX uniq_cart_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cart_item (id INT AUTO_INCREMENT NOT NULL, quantity NUMERIC(12, 3) NOT NULL, options_snapshot JSON DEFAULT NULL, position INT NOT NULL, cart_id INT NOT NULL, variant_id INT NOT NULL, INDEX idx_cart_item_variant (variant_id), INDEX IDX_F0FE25271AD5CDBF (cart_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25273B69A9AF FOREIGN KEY (variant_id) REFERENCES catalog_product_variant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25271AD5CDBF');
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25273B69A9AF');
        $this->addSql('DROP TABLE cart');
        $this->addSql('DROP TABLE cart_item');
    }
}

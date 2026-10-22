<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** E12 PR-3/PR-10: кошик покупця (FK + один на покупця), обране й переглянуті. */
final class Version20261011110322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Кошик покупця: FK customer_id SET NULL і unique; таблиці customer_wishlist і customer_viewed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE cart SET customer_id = NULL WHERE customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM customer)');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B79395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_cart_customer ON cart (customer_id)');
        $this->addSql('CREATE TABLE customer_wishlist (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_B024691E9395C3F3 (customer_id), INDEX IDX_B024691E4584665A (product_id), UNIQUE INDEX uniq_customer_wishlist (customer_id, product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customer_viewed (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, viewed_at DATETIME NOT NULL, INDEX IDX_464DC5554584665A (product_id), UNIQUE INDEX uniq_customer_viewed (customer_id, product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE customer_wishlist ADD CONSTRAINT FK_F2D11B939395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_wishlist ADD CONSTRAINT FK_F2D11B934584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_viewed ADD CONSTRAINT FK_4B5A8E0E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_viewed ADD CONSTRAINT FK_4B5A8E0E4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_wishlist DROP FOREIGN KEY FK_F2D11B939395C3F3');
        $this->addSql('ALTER TABLE customer_wishlist DROP FOREIGN KEY FK_F2D11B934584665A');
        $this->addSql('ALTER TABLE customer_viewed DROP FOREIGN KEY FK_4B5A8E0E9395C3F3');
        $this->addSql('ALTER TABLE customer_viewed DROP FOREIGN KEY FK_4B5A8E0E4584665A');
        $this->addSql('DROP TABLE customer_wishlist');
        $this->addSql('DROP TABLE customer_viewed');
        $this->addSql('ALTER TABLE cart DROP FOREIGN KEY FK_BA388B79395C3F3');
        $this->addSql('DROP INDEX uniq_cart_customer ON cart');
    }
}

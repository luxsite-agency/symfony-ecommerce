<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013045149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Відгуки на товари: catalog_product_review (модерація, відповідь магазину) і агрегат catalog_product_rating';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_product_review (id INT AUTO_INCREMENT NOT NULL, rating SMALLINT NOT NULL, text LONGTEXT NOT NULL, locale VARCHAR(12) NOT NULL, status VARCHAR(16) NOT NULL, verified_purchase TINYINT NOT NULL, demo TINYINT NOT NULL, reply_text LONGTEXT DEFAULT NULL, reply_by VARCHAR(180) DEFAULT NULL, replied_at DATETIME DEFAULT NULL, moderated_by VARCHAR(180) DEFAULT NULL, moderated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, product_id INT NOT NULL, customer_id INT NOT NULL, reply_user_id INT DEFAULT NULL, INDEX idx_product_review_product_status (product_id, status, created_at), INDEX idx_product_review_status (status, created_at), INDEX idx_product_review_customer (customer_id, created_at), UNIQUE INDEX uniq_product_review_customer (product_id, customer_id), INDEX IDX_884AD26F4584665A (product_id), INDEX IDX_884AD26F9395C3F3 (customer_id), INDEX IDX_884AD26FBAD0BA57 (reply_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_product_review ADD CONSTRAINT FK_884AD26F4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_review ADD CONSTRAINT FK_884AD26F9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_review ADD CONSTRAINT FK_884AD26FBAD0BA57 FOREIGN KEY (reply_user_id) REFERENCES security_user (id) ON DELETE SET NULL');
        // агрегат схвалених відгуків (ProductRatings): рядок лише для товарів з відгуками; не сутність Doctrine — пишеться DBAL-ом
        $this->addSql('CREATE TABLE catalog_product_rating (product_id INT NOT NULL, review_count INT NOT NULL, rating_sum INT NOT NULL, real_count INT NOT NULL, real_sum INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (product_id), CONSTRAINT FK_PRODUCT_RATING_PRODUCT FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_product_rating');
        $this->addSql('DROP TABLE catalog_product_review');
    }
}

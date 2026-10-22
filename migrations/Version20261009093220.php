<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Акції × групи покупців (рішення власника 07.10.2026): акція діє на покупця з групою лише з явного дозволу, порожній
 * список = лише роздріб. Join-таблиця Promotion.customerGroups; видалення акції чи групи прибирає зв'язок каскадом.
 */
final class Version20261009093220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ф3: акції діють на групи покупців лише з явного дозволу';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pricing_promotion_customer_group (promotion_id INT NOT NULL, customer_group_id INT NOT NULL, INDEX IDX_2D061FB9139DF194 (promotion_id), INDEX IDX_2D061FB9D2919A68 (customer_group_id), PRIMARY KEY (promotion_id, customer_group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pricing_promotion_customer_group ADD CONSTRAINT FK_2D061FB9139DF194 FOREIGN KEY (promotion_id) REFERENCES pricing_promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pricing_promotion_customer_group ADD CONSTRAINT FK_2D061FB9D2919A68 FOREIGN KEY (customer_group_id) REFERENCES customer_group (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pricing_promotion_customer_group DROP FOREIGN KEY FK_2D061FB9139DF194');
        $this->addSql('ALTER TABLE pricing_promotion_customer_group DROP FOREIGN KEY FK_2D061FB9D2919A68');
        $this->addSql('DROP TABLE pricing_promotion_customer_group');
    }
}

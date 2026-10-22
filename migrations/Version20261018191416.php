<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Вхід через Google (13.10.2026): customer.google_sub (унікальний sub з id_token), google_linked_at і google_email (адреса акаунта Google для показу; рев'ю M1). */
final class Version20261018191416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'customer: google_sub (unique), google_linked_at, google_email — Google як спосіб входу в кабінет';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD google_sub VARCHAR(255) DEFAULT NULL, ADD google_linked_at DATETIME DEFAULT NULL, ADD google_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_google_sub ON customer (google_sub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_customer_google_sub ON customer');
        $this->addSql('ALTER TABLE customer DROP google_sub, DROP google_linked_at, DROP google_email');
    }
}

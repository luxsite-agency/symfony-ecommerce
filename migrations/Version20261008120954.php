<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Рев'ю безпеки #45, п. 2: токен підтвердження email більше не несе пароль реєстранта (пароль задає власник скриньки на
 * сторінці з листа), колонка customer_auth_token.password_hash не потрібна.
 */
final class Version20261008120954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E12: customer_auth_token без password_hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_auth_token DROP password_hash');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_auth_token ADD password_hash VARCHAR(255) DEFAULT NULL');
    }
}

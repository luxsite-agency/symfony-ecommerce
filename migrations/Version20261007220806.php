<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Кінець акції «до цієї хвилини включно»: Promotion::setEndsAt тепер зберігає 59 секунд. Наявним акціям, збереженим
 * з адмінки без секунд (hh:mm:00), додаються 59 секунд, щоб і вони діяли в останню хвилину.
 */
final class Version20261007220806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pricing_promotion.ends_at: hh:mm:00 → hh:mm:59 (кінець включно до хвилини)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE pricing_promotion SET ends_at = ends_at + INTERVAL 59 SECOND WHERE ends_at IS NOT NULL AND SECOND(ends_at) = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE pricing_promotion SET ends_at = ends_at - INTERVAL 59 SECOND WHERE ends_at IS NOT NULL AND SECOND(ends_at) = 59');
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Мова адмінки в профілі користувача (повний переклад адмінки, 20.10.2026): вибір у меню переживає вихід і інший пристрій. */
final class Version20261020084054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'security_user.admin_locale — обрана мова інтерфейсу адмінки (null — типова)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE security_user ADD admin_locale VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE security_user DROP admin_locale');
    }
}

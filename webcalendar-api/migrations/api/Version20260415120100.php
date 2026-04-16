<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `cal_image` column required by webcalendar-core ≥ 4.1.0. Ported
 * from the legacy `migrations/004_add_cal_image.sql` which used a MySQL
 * stored procedure for idempotency; Doctrine's version table makes that
 * scaffolding unnecessary.
 *
 * Column is nullable `VARCHAR(2048)` placed after `cal_status` to match
 * the canonical core schema.
 */
final class Version20260415120100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add webcal_entry.cal_image column (ported from 004_add_cal_image.sql)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webcal_entry ADD COLUMN cal_image VARCHAR(2048) DEFAULT NULL AFTER cal_status');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webcal_entry DROP COLUMN cal_image');
    }
}

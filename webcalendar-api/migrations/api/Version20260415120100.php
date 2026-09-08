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
        // webcalendar-core has since absorbed cal_image into its own
        // mysql-schema.sql, so a database created from that schema already has
        // the column and this migration would fail with "Duplicate column
        // name". It still has to run for databases created before core picked
        // it up, so skip rather than delete.
        $this->skipIf(
            $schema->hasTable('webcal_entry') && $schema->getTable('webcal_entry')->hasColumn('cal_image'),
            'webcal_entry.cal_image already exists — shipped by webcalendar-core',
        );

        $this->addSql('ALTER TABLE webcal_entry ADD COLUMN cal_image VARCHAR(2048) DEFAULT NULL AFTER cal_status');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webcal_entry DROP COLUMN cal_image');
    }
}

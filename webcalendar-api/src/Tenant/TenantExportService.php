<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Exports all tenant data as a ZIP archive containing JSON and ICS files.
 */
final readonly class TenantExportService
{
    public function __construct(
        private TenantDatabaseManager $dbManager,
    ) {
    }

    /**
     * Exports tenant data as a ZIP file content string.
     *
     * @return string Raw ZIP file content
     */
    public function export(Tenant $tenant): string
    {
        $pdo = $this->dbManager->getConnection($tenant);

        $tmpFile = tempnam(sys_get_temp_dir(), 'wctng_export_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp file');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        // Export users
        $zip->addFromString('users.json', $this->exportTable($pdo, 'webcal_user'));

        // Export events
        $zip->addFromString('events.json', $this->exportTable($pdo, 'webcal_entry'));

        // Export categories
        $zip->addFromString('categories.json', $this->exportTable($pdo, 'webcal_categories'));

        // Export groups
        $zip->addFromString('groups.json', $this->exportTable($pdo, 'webcal_group'));
        $zip->addFromString('group_members.json', $this->exportTable($pdo, 'webcal_group_user'));

        // Export layers
        $zip->addFromString('layers.json', $this->exportTable($pdo, 'webcal_user_layers'));

        // Export participants
        $zip->addFromString('participants.json', $this->exportTable($pdo, 'webcal_entry_user'));

        // Export events as ICS
        $zip->addFromString('events.ics', $this->exportEventsAsIcs($pdo));

        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        if ($content === false) {
            throw new \RuntimeException('Failed to read ZIP file');
        }

        return $content;
    }

    private function exportTable(\PDO $pdo, string $table): string
    {
        try {
            $stmt = $pdo->query("SELECT * FROM {$table}");
            if ($stmt === false) {
                return '[]';
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\PDOException) {
            return '[]';
        }
    }

    private function exportEventsAsIcs(\PDO $pdo): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WebCalendar//WCTNG Export//EN'];

        try {
            $stmt = $pdo->query('SELECT * FROM webcal_entry');
            if ($stmt !== false) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $lines[] = 'BEGIN:VEVENT';
                    /** @var string $name */
                    $name = $row['cal_name'] ?? '';
                    /** @var int|string $date */
                    $date = $row['cal_date'] ?? '';
                    /** @var int|string $id */
                    $id = $row['cal_id'] ?? 0;
                    $lines[] = "UID:wctng-{$id}@webcalendar";
                    $lines[] = 'SUMMARY:' . str_replace(["\r", "\n"], ' ', $name);
                    $lines[] = "DTSTART:{$date}";
                    $lines[] = 'END:VEVENT';
                }
            }
        } catch (\PDOException) {
            // Skip ICS if table doesn't exist
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }
}

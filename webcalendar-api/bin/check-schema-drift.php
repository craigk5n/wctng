#!/usr/bin/env php
<?php

/**
 * CI guard for webcalendar-core schema drift.
 *
 * webcalendar-core ships its schema as a flat mysql-schema.sql with no
 * migrations and no schema version, so bumping the package silently changes
 * what the app expects the database to look like. v4.3.0 -> v4.10.0 added five
 * tables and four columns; the first symptom was a 500 on /api/v2/events
 * ("Unknown column 'cat_is_tag'").
 *
 * Two checks, because drift is introduced and observed at different moments:
 *
 *   (default)  vendor schema vs schema/core-schema-snapshot.json — fires the
 *              moment someone bumps core, needs no database, so this is the
 *              one CI runs. A CI check against a live database would never
 *              fail: CI builds its database from the same vendor file.
 *
 *   --db       live database vs vendor schema — fires when a deployment has
 *              not had the resulting DDL applied yet. For operators and dev
 *              machines; reads DATABASE_URL.
 *
 * After reviewing a diff and writing the DDL, re-baseline with
 * --update-snapshot.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$schemaFile = $root . '/vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/mysql-schema.sql';
$snapshotFile = $root . '/schema/core-schema-snapshot.json';

$args = array_slice($argv, 1);
$mode = in_array('--db', $args, true) ? 'db' : 'snapshot';
$update = in_array('--update-snapshot', $args, true);

/**
 * @return array<string, list<string>> table => sorted column names
 */
function parseSchema(string $sql): array
{
    $tables = [];
    preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?\s*\((.*?)\n\)[^;]*;/is', $sql, $m, PREG_SET_ORDER);

    foreach ($m as $match) {
        $cols = [];
        foreach (explode("\n", $match[2]) as $line) {
            $line = trim(rtrim(trim($line), ','));
            if ($line === '' || preg_match('/^(PRIMARY|KEY|UNIQUE|INDEX|CONSTRAINT|FOREIGN|FULLTEXT|--)/i', $line)) {
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s/', $line, $c)) {
                $cols[] = $c[1];
            }
        }
        sort($cols);
        $tables[$match[1]] = $cols;
    }

    ksort($tables);

    return $tables;
}

if (!is_file($schemaFile)) {
    fwrite(STDERR, "webcalendar-core schema not found at {$schemaFile}\nRun composer install.\n");
    exit(2);
}

$sql = (string) file_get_contents($schemaFile);
$expected = parseSchema($sql);

if ($expected === []) {
    fwrite(STDERR, "Parsed zero tables from {$schemaFile} — the guard itself is broken.\n");
    exit(2);
}

$coreVersion = 'unknown';
$installed = $root . '/vendor/composer/installed.json';
if (is_file($installed)) {
    /** @var array{packages?: list<array{name?: string, version?: string}>} $data */
    $data = json_decode((string) file_get_contents($installed), true);
    foreach ($data['packages'] ?? [] as $pkg) {
        if (($pkg['name'] ?? '') === 'craigk5n/webcalendar-core') {
            $coreVersion = $pkg['version'] ?? 'unknown';
        }
    }
}

if ($update) {
    if (!is_dir(dirname($snapshotFile)) && !mkdir(dirname($snapshotFile), 0o775, true)) {
        fwrite(STDERR, 'Could not create ' . dirname($snapshotFile) . "\n");
        exit(2);
    }

    $written = file_put_contents(
        $snapshotFile,
        json_encode(
            ['core_version' => $coreVersion, 'tables' => $expected],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n",
    );

    if ($written === false) {
        fwrite(STDERR, "Could not write {$snapshotFile}\n");
        exit(2);
    }

    echo "Snapshot written for craigk5n/webcalendar-core {$coreVersion}: "
        . count($expected) . " tables.\n";
    exit(0);
}

/**
 * @param array<string, list<string>> $expected
 * @param array<string, list<string>> $actual
 * @return list<string>
 */
function diffSchema(array $expected, array $actual): array
{
    $missing = [];
    foreach ($expected as $table => $cols) {
        if (!isset($actual[$table])) {
            $missing[] = "table {$table} (" . count($cols) . ' columns)';
            continue;
        }
        foreach (array_diff($cols, $actual[$table]) as $col) {
            $missing[] = "column {$table}.{$col}";
        }
    }

    return $missing;
}

if ($mode === 'db') {
    $url = getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '');
    if (!is_string($url) || $url === '') {
        fwrite(STDERR, "--db needs DATABASE_URL in the environment.\n");
        exit(2);
    }

    $p = parse_url($url);
    if (!is_array($p) || !isset($p['path'])) {
        fwrite(STDERR, "Could not parse DATABASE_URL.\n");
        exit(2);
    }

    $dbName = ltrim($p['path'], '/');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', $p['port'] ?? 3306, $dbName);

    try {
        $pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // MySQL 8 returns information_schema column names uppercased; alias
        // them so the case is ours rather than the server's.
        $stmt = $pdo->prepare(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = :s',
        );
        $stmt->execute(['s' => $dbName]);
    } catch (PDOException $e) {
        fwrite(STDERR, 'Could not read the database: ' . $e->getMessage() . "\n");
        exit(2);
    }

    $actual = [];
    /** @var array{t: string, c: string} $row */
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actual[$row['t']][] = $row['c'];
    }

    $missing = diffSchema($expected, $actual);

    if ($missing === []) {
        echo "OK: {$dbName} has every table and column webcalendar-core {$coreVersion} declares.\n";
        exit(0);
    }

    fwrite(STDERR, "FAIL: {$dbName} is behind webcalendar-core {$coreVersion} — "
        . count($missing) . " item(s) missing:\n\n");
    foreach ($missing as $item) {
        fwrite(STDERR, "  {$item}\n");
    }
    fwrite(STDERR, "\nApply the additive DDL, then re-run. Fresh installs get this from\n");
    fwrite(STDERR, "the mounted mysql-schema.sql; existing databases need it by hand.\n");
    exit(1);
}

if (!is_file($snapshotFile)) {
    fwrite(STDERR, "No snapshot at {$snapshotFile}.\nCreate one with: composer check-schema-drift -- --update-snapshot\n");
    exit(2);
}

/** @var array{core_version?: string, tables?: array<string, list<string>>} $snapshot */
$snapshot = json_decode((string) file_get_contents($snapshotFile), true);
$recorded = $snapshot['tables'] ?? [];
$recordedVersion = $snapshot['core_version'] ?? 'unknown';

$added = diffSchema($expected, $recorded);
$removed = diffSchema($recorded, $expected);

if ($added === [] && $removed === []) {
    echo "OK: webcalendar-core {$coreVersion} schema matches the snapshot ("
        . count($expected) . " tables).\n";
    exit(0);
}

fwrite(STDERR, "FAIL: webcalendar-core schema changed since the snapshot.\n");
fwrite(STDERR, "  snapshot: {$recordedVersion}\n  installed: {$coreVersion}\n\n");

foreach ($added as $item) {
    fwrite(STDERR, "  + {$item}\n");
}
foreach ($removed as $item) {
    fwrite(STDERR, "  - {$item}\n");
}

fwrite(STDERR, "\nwebcalendar-core ships no migrations, so an existing database will not\n");
fwrite(STDERR, "pick these up on its own and will fail at runtime on the missing columns.\n");
fwrite(STDERR, "Write the DDL (a migration under migrations/api, or an upgrade note), apply\n");
fwrite(STDERR, "it, then re-baseline: composer check-schema-drift -- --update-snapshot\n");
exit(1);

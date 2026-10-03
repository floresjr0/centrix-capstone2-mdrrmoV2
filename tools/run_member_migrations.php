<?php
/**
 * Apply member-tracking migrations required for individual evacuee roster.
 * Run once: php tools/run_member_migrations.php
 */
require_once __DIR__ . '/../pages/config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrations = [
    __DIR__ . '/../database_schema/add_evac_registration_members.sql',
    __DIR__ . '/../database_schema/add_evac_registration_members_archive.sql',
    __DIR__ . '/../database_schema/add_archive_registration_source.sql',
    __DIR__ . '/../database_schema/add_family_members.sql',
];

foreach ($migrations as $file) {
    if (!is_file($file)) {
        echo "Skip missing: $file\n";
        continue;
    }
    echo "Applying: " . basename($file) . "\n";
    $sql = file_get_contents($file);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate key name')) {
                echo "  (already applied) " . substr($statement, 0, 60) . "...\n";
                continue;
            }
            throw $e;
        }
    }
}

echo "Done.\n";

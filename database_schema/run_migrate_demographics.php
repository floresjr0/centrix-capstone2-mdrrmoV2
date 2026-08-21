<?php
require_once __DIR__ . '/../pages/db.php';

$pdo = db();
$tables = [
    'family_profiles' => false,
    'evac_registrations' => true,
    'evac_registrations_archive' => true,
    'citizen_household' => false,
];
$columns = ['pregnant_women', 'lactating_mothers', 'infants_toddlers'];

foreach ($tables as $table => $bigInt) {
    foreach ($columns as $col) {
        $type = $bigInt
            ? 'int(10) UNSIGNED NOT NULL DEFAULT 0'
            : 'tinyint(3) UNSIGNED NOT NULL DEFAULT 0';
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $type");
            echo "Added $col to $table\n";
        } catch (Throwable $e) {
            echo "Skip $table.$col: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nfamily_profiles columns:\n";
foreach ($pdo->query('SHOW COLUMNS FROM family_profiles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo '  ' . $row['Field'] . "\n";
}

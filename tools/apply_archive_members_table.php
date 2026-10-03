<?php
require_once __DIR__ . '/../pages/config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = file_get_contents(__DIR__ . '/../database_schema/add_evac_registration_members_archive.sql');
$pdo->exec($sql);
echo "evac_registration_members_archive ready\n";

$sourceSql = file_get_contents(__DIR__ . '/../database_schema/add_archive_registration_source.sql');
try {
    $pdo->exec($sourceSql);
    echo "source_user_id on archive ready\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "source_user_id on archive already exists\n";
    } else {
        throw $e;
    }
}

<?php
require_once __DIR__ . '/../pages/config.php';
require_once __DIR__ . '/../pages/admin_report_helpers.php';
require_once __DIR__ . '/../pages/registration_member_helpers.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Tables ===\n";
foreach ($pdo->query("SHOW TABLES LIKE 'evac_registration%'") as $r) {
    echo implode('', $r) . "\n";
}

echo "\n=== Column check ===\n";
echo 'evac_registration_members: ' . (ar_table_exists($pdo, 'evac_registration_members') ? 'yes' : 'no') . "\n";
echo 'family_members: ' . (ar_table_exists($pdo, 'family_members') ? 'yes' : 'no') . "\n";
echo 'source_user_id col: ' . (ar_column_exists($pdo, 'evac_registrations', 'source_user_id') ? 'yes' : 'no') . "\n";

echo "\n=== Registrations sample ===\n";
$sql = "
SELECT er.id, er.family_head_name, er.registration_mode, er.source_user_id, er.total_members,
       (SELECT COUNT(*) FROM evac_registration_members erm WHERE erm.registration_id = er.id AND erm.is_present = 1) AS present_rows
FROM evac_registrations er
ORDER BY er.id DESC
LIMIT 15
";
foreach ($pdo->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Member rows sample ===\n";
if (ar_table_exists($pdo, 'evac_registration_members')) {
    foreach ($pdo->query('SELECT registration_id, full_name, is_household_head, is_present FROM evac_registration_members ORDER BY id DESC LIMIT 20') as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== Archive batches ===\n";
foreach ($pdo->query('SELECT archive_label, COUNT(*) c FROM evac_registrations_archive GROUP BY archive_label') as $row) {
    echo json_encode($row) . "\n";
}

echo "\n=== Archive registrations sample ===\n";
$archiveRegs = $pdo->query("
    SELECT era.id AS archive_registration_id, era.original_id, era.family_head_name,
           era.contact_number, era.birthday, era.source_user_id, era.total_members, era.archive_label
    FROM evac_registrations_archive era
    ORDER BY era.id DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($archiveRegs as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Archive member rows ===\n";
echo 'archive members table: ' . (ar_table_exists($pdo, 'evac_registration_members_archive') ? 'yes' : 'no') . "\n";
if (ar_table_exists($pdo, 'evac_registration_members_archive')) {
    foreach ($pdo->query('SELECT archive_registration_id, full_name, is_household_head FROM evac_registration_members_archive LIMIT 20') as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== Archive profile resolution test ===\n";
foreach (array_slice($archiveRegs, 0, 5) as $reg) {
    $aid = (int)$reg['archive_registration_id'];
    $label = $reg['archive_label'];
    $arrival = ar_bulk_archive_members($pdo, $label, [$aid]);
    $profile = ar_profile_members_for_registration($pdo, $reg);
    $resolved = ar_resolve_registration_members($pdo, $reg, $arrival[$aid] ?? [], $profile);
    $names = array_map(fn($m) => ($m['full_name'] ?? '') . (!empty($m['_is_note']) ? ' [NOTE]' : ''), $resolved);
    echo "Archive #{$aid} {$reg['family_head_name']}: arrival=" . count($arrival[$aid] ?? []) . " profile=" . count($profile) . " => " . implode(', ', $names) . "\n";
}

echo "\n=== Citizen families with family_members ===\n";
foreach ($pdo->query("
    SELECT u.id, u.full_name, u.contact_number, u.birthday, COUNT(fm.id) AS member_count
    FROM users u
    LEFT JOIN family_members fm ON fm.user_id = u.id
    WHERE u.role = 'citizen'
    GROUP BY u.id
    LIMIT 20
") as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== All family_members ===\n";
foreach ($pdo->query('SELECT user_id, full_name, sex, primary_category FROM family_members') as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Archive #12 Juan Flores (no source_user_id) ===\n";
$reg12 = ['archive_registration_id' => 12, 'family_head_name' => 'Juan Flores', 'contact_number' => '09686971314', 'birthday' => '2005-06-09', 'source_user_id' => null, 'total_members' => 2];
$resolved12 = ar_resolve_registration_members($pdo, $reg12, [], []);
echo implode(', ', array_column(array_filter($resolved12, fn($r) => empty($r['_is_note'])), 'full_name')) . "\n";

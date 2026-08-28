<?php
/**
 * admin/expected_counts.php
 *
 * JSON endpoint polled by admin/index.php every 30s.
 * Returns expected evacuee counts (household-inclusive) for all non-closed centers.
 *
 * Response:
 *   { "ok": true, "total_expected": 12, "centers": [ { "id": 1, "expected_count": 5, "max_capacity_people": 100 }, … ] }
 */

require_once __DIR__ . '/../pages/session.php';
require_login('admin');

header('Content-Type: application/json');

$pdo = db();

$stmt = $pdo->query("
    SELECT
        ec.id,
        ec.max_capacity_people,
        COALESCE(t.expected_count, 0) AS expected_count
    FROM evacuation_centers ec
    LEFT JOIN (
        SELECT
            nt.center_id,
            SUM(COALESCE(ch.total_members, 1)) AS expected_count
        FROM evac_navigation_tracking nt
        LEFT JOIN family_profiles ch ON ch.user_id = nt.user_id
        WHERE nt.status = 'navigating'
        GROUP BY nt.center_id
    ) t ON t.center_id = ec.id
    WHERE ec.status != 'closed'
    ORDER BY expected_count DESC, ec.id ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$centers = [];
$totalExpected = 0;
foreach ($rows as $r) {
    $count = (int)$r['expected_count'];
    $centers[] = [
        'id'                  => (int)$r['id'],
        'expected_count'      => $count,
        'max_capacity_people' => (int)$r['max_capacity_people'],
    ];
    $totalExpected += $count;
}

echo json_encode([
    'ok'             => true,
    'total_expected' => $totalExpected,
    'centers'        => $centers,
]);

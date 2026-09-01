<?php
/**
 * Public evacuation center data for offline emergency cache.
 * No authentication required — safe fields only.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../pages/db.php';

try {
    $pdo = db();

    $stmt = $pdo->query("
        SELECT
            c.id,
            c.name,
            c.address,
            c.status,
            c.max_capacity_people,
            c.max_capacity_families,
            COALESCE(SUM(r.total_members), 0) AS current_occupancy,
            c.max_capacity_people - COALESCE(SUM(r.total_members), 0) AS slots_remaining,
            b.name AS barangay,
            u.full_name AS coordinator_name,
            u.contact_number AS coordinator_contact
        FROM evacuation_centers c
        JOIN barangays b ON b.id = c.barangay_id
        LEFT JOIN users u ON u.id = c.coordinator_user_id
        LEFT JOIN evac_registrations r ON r.center_id = c.id
        WHERE c.status != 'closed'
        GROUP BY c.id, c.name, c.address, c.status, c.max_capacity_people, c.max_capacity_families,
                 b.name, u.full_name, u.contact_number
        ORDER BY c.name
    ");

    $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($centers as &$row) {
        $row['id'] = (int) $row['id'];
        $row['max_capacity_people'] = (int) $row['max_capacity_people'];
        $row['max_capacity_families'] = (int) $row['max_capacity_families'];
        $row['current_occupancy'] = (int) $row['current_occupancy'];
        $row['slots_remaining'] = (int) $row['slots_remaining'];
        $row['coordinator_name'] = $row['coordinator_name'] ?? '';
        $row['coordinator_contact'] = $row['coordinator_contact'] ?? '';
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'synced_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'centers' => $centers,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
    ]);
}

<?php
require_once __DIR__ . '/../../pages/session.php';
require_once __DIR__ . '/../../pages/family_adjustment.php';

require_login('coordinator');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$pdo  = db();
$user = current_user();
$centerId = isset($_GET['center_id']) ? (int)$_GET['center_id'] : 0;

if ($centerId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => ['Center id is required.']]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id FROM evacuation_centers WHERE id = ? AND coordinator_user_id = ? LIMIT 1'
);
$stmt->execute([$centerId, $user['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['Center not assigned to you.']]);
    exit;
}

$regsStmt = $pdo->prepare(
    'SELECT r.*, b.name AS barangay_name
     FROM evac_registrations r
     JOIN barangays b ON b.id = r.barangay_id
     WHERE r.center_id = ?
     ORDER BY r.created_at DESC'
);
$regsStmt->execute([$centerId]);
$rows = $regsStmt->fetchAll(PDO::FETCH_ASSOC);

$roster = array_map('registration_to_roster_item', $rows);

echo json_encode([
    'success' => true,
    'center_id' => $centerId,
    'registrations' => $roster,
]);

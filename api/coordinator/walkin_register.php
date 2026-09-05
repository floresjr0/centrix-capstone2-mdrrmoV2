<?php
require_once __DIR__ . '/../../pages/session.php';
require_once __DIR__ . '/../../pages/walkin_registration.php';

require_login('coordinator');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$pdo  = db();
$user = current_user();

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$centerId = (int)($data['center_id'] ?? 0);
if ($centerId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_center', 'message' => 'Center id is required.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id FROM evacuation_centers WHERE id = ? AND coordinator_user_id = ? LIMIT 1'
);
$stmt->execute([$centerId, $user['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'Center not assigned to you.']);
    exit;
}

$result = register_walkin_family($pdo, $centerId, (int)$user['id'], $data);

if (!$result['success']) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'errors' => $result['errors'] ?? ['Registration failed.'],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $result['id'],
    'already_synced' => !empty($result['already_synced']),
    'center_id' => $centerId,
]);

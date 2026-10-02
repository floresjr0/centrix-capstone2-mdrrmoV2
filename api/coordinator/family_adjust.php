<?php
require_once __DIR__ . '/../../pages/session.php';
require_once __DIR__ . '/../../pages/family_adjustment.php';
require_once __DIR__ . '/../../pages/registration_member_helpers.php';

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

$centerId  = (int)($data['center_id'] ?? 0);
$regId     = (int)($data['reg_id'] ?? 0);
$action    = (string)($data['action'] ?? 'adjust');
$field     = (string)($data['field'] ?? '');
$delta     = (int)($data['delta'] ?? 0);
$memberId  = (int)($data['member_id'] ?? 0);
$localUuid = trim((string)($data['client_adjustment_uuid'] ?? ''));

if ($centerId <= 0 || $regId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => ['Center and registration id are required.']]);
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

if ($action === 'add_member') {
    $result = rm_add_registration_member($pdo, $centerId, $regId, $data);
} elseif ($action === 'remove_member') {
    if ($memberId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => ['Member id is required.']]);
        exit;
    }
    $result = rm_remove_registration_member($pdo, $centerId, $regId, $memberId);
} else {
    $result = apply_family_adjustment(
        $pdo,
        $centerId,
        $regId,
        $field,
        $delta,
        $localUuid !== '' ? $localUuid : null
    );
}

if (!$result['success']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $result['errors'] ?? ['Adjustment failed.']]);
    exit;
}

$regRow = $result['registration'] ?? fetch_registration_for_center($pdo, $regId, $centerId);
echo json_encode([
    'success' => true,
    'already_applied' => !empty($result['already_applied']),
    'registration' => $regRow ? rm_registration_to_roster_item($regRow, $pdo) : null,
    'members' => $result['members'] ?? null,
]);

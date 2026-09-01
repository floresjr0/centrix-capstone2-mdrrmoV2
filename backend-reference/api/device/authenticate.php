<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/device_auth_helpers.php';

$data = deviceAuthRequirePost();

$deviceId = trim((string) ($data['device_id'] ?? ''));
$deviceToken = trim((string) ($data['device_token'] ?? ''));

if ($deviceId === '' || $deviceToken === '') {
    deviceAuthJson(400, ['success' => false, 'error' => 'invalid_request']);
}

$row = deviceAuthFindActiveToken($pdo, $deviceId);
if ($row === null) {
    deviceAuthJson(401, ['success' => false, 'error' => 'invalid_device']);
}

if (!deviceAuthVerifyToken($deviceToken, $row['token_hash'])) {
    deviceAuthJson(401, ['success' => false, 'error' => 'invalid_token']);
}

$update = $pdo->prepare('UPDATE citizen_device_tokens SET last_used_at = NOW() WHERE id = :id');
$update->execute([':id' => $row['id']]);

deviceAuthStartSessionForUser($pdo, (int) $row['user_id']);

deviceAuthJson(200, [
    'success' => true,
    'redirect' => '/pages/citizen_dashboard.php',
]);

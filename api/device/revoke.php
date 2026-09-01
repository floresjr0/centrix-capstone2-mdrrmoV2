<?php
declare(strict_types=1);

require_once __DIR__ . '/device_auth_helpers.php';

$pdo = deviceAuthBootstrap();
$data = deviceAuthRequirePost();
$user = deviceAuthRequireLoggedInCitizen($pdo);

$deviceId = trim((string) ($data['device_id'] ?? ''));

if (!deviceAuthValidateDeviceId($deviceId)) {
    deviceAuthJson(400, ['success' => false, 'error' => 'authentication_failed']);
}

$stmt = $pdo->prepare(
    'UPDATE citizen_device_tokens
     SET revoked_at = NOW()
     WHERE device_id = ?
       AND user_id = ?
       AND revoked_at IS NULL'
);
$stmt->execute([$deviceId, (int) $user['id']]);

deviceAuthJson(200, [
    'success' => true,
    'revoked' => $stmt->rowCount() > 0,
]);

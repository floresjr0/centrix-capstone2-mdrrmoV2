<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/device_auth_helpers.php';

$data = deviceAuthRequirePost();

$deviceId = trim((string) ($data['device_id'] ?? ''));

if ($deviceId === '') {
    deviceAuthJson(400, ['success' => false, 'error' => 'invalid_request']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare(
    'UPDATE citizen_device_tokens
     SET revoked_at = NOW()
     WHERE device_id = :device_id
       AND (:user_id = 0 OR user_id = :user_id)
       AND revoked_at IS NULL'
);
$stmt->execute([
    ':device_id' => $deviceId,
    ':user_id' => $userId,
]);

deviceAuthJson(200, [
    'success' => true,
    'revoked' => $stmt->rowCount() > 0,
]);

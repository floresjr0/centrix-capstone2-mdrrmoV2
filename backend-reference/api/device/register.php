<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/device_auth_helpers.php';

$data = deviceAuthRequirePost();
$userId = deviceAuthRequireLoggedInCitizen($pdo);

$deviceId = trim((string) ($data['device_id'] ?? ''));
$deviceToken = trim((string) ($data['device_token'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));

if ($deviceId === '' || $deviceToken === '' || strlen($deviceToken) < 32) {
    deviceAuthJson(400, ['success' => false, 'error' => 'invalid_request']);
}

$existing = deviceAuthFindActiveToken($pdo, $deviceId);
if ($existing !== null) {
    deviceAuthJson(409, ['success' => false, 'error' => 'device_already_registered']);
}

$expiresAt = (new DateTimeImmutable('now'))
    ->modify('+' . DEVICE_TOKEN_TTL_DAYS . ' days')
    ->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'INSERT INTO citizen_device_tokens (user_id, device_id, token_hash, device_label, expires_at)
     VALUES (:user_id, :device_id, :token_hash, :device_label, :expires_at)'
);
$stmt->execute([
    ':user_id' => $userId,
    ':device_id' => $deviceId,
    ':token_hash' => deviceAuthHashToken($deviceToken),
    ':device_label' => $username !== '' ? $username : 'Android device',
    ':expires_at' => $expiresAt,
]);

deviceAuthJson(200, [
    'success' => true,
    'device_id' => $deviceId,
    'expires_at' => $expiresAt,
]);

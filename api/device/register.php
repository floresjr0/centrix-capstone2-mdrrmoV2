<?php
declare(strict_types=1);

require_once __DIR__ . '/device_auth_helpers.php';

$pdo = deviceAuthBootstrap();
$data = deviceAuthRequirePost();
$user = deviceAuthRequireLoggedInCitizen($pdo);

$deviceId = trim((string) ($data['device_id'] ?? ''));
$deviceToken = trim((string) ($data['device_token'] ?? ''));
$deviceLabel = trim((string) ($data['device_label'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));

if (!deviceAuthValidateDeviceId($deviceId) || !deviceAuthValidateDeviceToken($deviceToken)) {
    deviceAuthJson(400, [
        'success' => false,
        'error' => 'invalid_device_credential',
        'message' => 'Device registration payload is invalid.',
    ]);
}

// Replace any prior registration on this device (account switch / re-enroll).
deviceAuthRevokeDeviceTokens($pdo, $deviceId);

$expiresAt = (new DateTimeImmutable('now'))
    ->modify('+' . DEVICE_TOKEN_TTL_DAYS . ' days')
    ->format('Y-m-d H:i:s');

$label = $deviceLabel !== '' ? $deviceLabel : $username;
if ($label === '') {
    $label = $user['full_name'] ?? 'Android device';
}
if (strlen($label) > 120) {
    $label = mb_substr($label, 0, 120);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO citizen_device_tokens (user_id, device_id, token_hash, device_label, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $user['id'],
        $deviceId,
        deviceAuthHashToken($deviceToken),
        $label,
        $expiresAt,
    ]);
} catch (Throwable $e) {
    deviceAuthJson(500, [
        'success' => false,
        'error' => 'database_error',
        'message' => 'Could not save device token. Check citizen_device_tokens table.',
    ]);
}

deviceAuthJson(200, [
    'success' => true,
    'device_id' => $deviceId,
    'expires_at' => $expiresAt,
    'email' => $user['email'] ?? '',
]);

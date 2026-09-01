<?php
declare(strict_types=1);

require_once __DIR__ . '/device_auth_helpers.php';

$pdo = deviceAuthBootstrap();
$data = deviceAuthRequirePost();

$deviceId = trim((string) ($data['device_id'] ?? ''));
$deviceToken = trim((string) ($data['device_token'] ?? ''));

if (!deviceAuthValidateDeviceId($deviceId) || !deviceAuthValidateDeviceToken($deviceToken)) {
    deviceAuthJson(401, ['success' => false, 'error' => 'authentication_failed']);
}

$row = deviceAuthFindActiveToken($pdo, $deviceId);
if ($row === null || !deviceAuthVerifyToken($deviceToken, (string) $row['token_hash'])) {
    deviceAuthJson(401, ['success' => false, 'error' => 'authentication_failed']);
}

$update = $pdo->prepare('UPDATE citizen_device_tokens SET last_used_at = NOW() WHERE id = ?');
$update->execute([(int) $row['id']]);

deviceAuthStartSessionForUser($pdo, (int) $row['user_id']);

$redirectPath = app_url('pages/citizen_dashboard.php');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirect = $host !== '' ? ($scheme . '://' . $host . $redirectPath) : $redirectPath;

deviceAuthJson(200, [
    'success' => true,
    'redirect' => $redirect,
]);

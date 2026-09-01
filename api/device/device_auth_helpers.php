<?php
/**
 * Shared helpers for citizen device-token (fingerprint-gated) authentication.
 * Integrates with the existing MDRRMO session bootstrap in pages/session.php.
 */

declare(strict_types=1);

const DEVICE_TOKEN_TTL_DAYS = 90;
const DEVICE_TOKEN_MIN_BYTES = 32;

function deviceAuthBootstrap(): PDO
{
    require_once __DIR__ . '/../../pages/db.php';
    require_once __DIR__ . '/../../pages/session.php';

    return db();
}

function deviceAuthJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function deviceAuthRequirePost(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        deviceAuthJson(405, ['success' => false, 'error' => 'authentication_failed']);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        deviceAuthJson(400, ['success' => false, 'error' => 'authentication_failed']);
    }

    return $data;
}

function deviceAuthHashToken(string $token): string
{
    return password_hash($token, PASSWORD_DEFAULT);
}

function deviceAuthVerifyToken(string $token, string $hash): bool
{
    return password_verify($token, $hash);
}

/**
 * Load a citizen eligible for device login (same checks as index.php password login).
 */
function deviceAuthLoadCitizen(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.*, b.municipality, b.province
         FROM users u
         JOIN barangays b ON b.id = u.barangay_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }

    if (($user['role'] ?? '') !== 'citizen') {
        return null;
    }
    if ((int)($user['is_active'] ?? 0) !== 1) {
        return null;
    }
    if ((int)($user['is_email_verified'] ?? 0) !== 1) {
        return null;
    }
    if (($user['municipality'] ?? '') !== 'San Ildefonso' || ($user['province'] ?? '') !== 'Bulacan') {
        return null;
    }

    return $user;
}

/**
 * Establish the same PHP session as password login: only $_SESSION['user_id'].
 */
function deviceAuthStartSessionForUser(PDO $pdo, int $userId): void
{
    $user = deviceAuthLoadCitizen($pdo, $userId);
    if (!$user) {
        deviceAuthJson(401, ['success' => false, 'error' => 'authentication_failed']);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

/**
 * Require an authenticated citizen session (for register/revoke).
 */
function deviceAuthRequireLoggedInCitizen(PDO $pdo): array
{
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'citizen') {
        deviceAuthJson(401, [
            'success' => false,
            'error' => 'not_logged_in',
            'message' => 'Citizen login session required.',
        ]);
    }
    if ((int)($user['is_email_verified'] ?? 0) !== 1) {
        deviceAuthJson(401, [
            'success' => false,
            'error' => 'email_not_verified',
            'message' => 'Verify your email before enabling fingerprint login.',
        ]);
    }
    if (($user['municipality'] ?? '') !== 'San Ildefonso' || ($user['province'] ?? '') !== 'Bulacan') {
        deviceAuthJson(403, [
            'success' => false,
            'error' => 'location_not_allowed',
            'message' => 'Fingerprint login is only available for San Ildefonso, Bulacan residents.',
        ]);
    }

    return $user;
}

function deviceAuthFindActiveToken(PDO $pdo, string $deviceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM citizen_device_tokens
         WHERE device_id = ?
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function deviceAuthRevokeDeviceTokens(PDO $pdo, string $deviceId, ?int $userId = null): void
{
    if ($userId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE citizen_device_tokens
             SET revoked_at = NOW()
             WHERE device_id = ?
               AND user_id = ?
               AND revoked_at IS NULL'
        );
        $stmt->execute([$deviceId, $userId]);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE citizen_device_tokens
         SET revoked_at = NOW()
         WHERE device_id = ?
           AND revoked_at IS NULL'
    );
    $stmt->execute([$deviceId]);
}

function deviceAuthValidateDeviceId(string $deviceId): bool
{
    return $deviceId !== ''
        && strlen($deviceId) <= 64
        && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $deviceId);
}

function deviceAuthValidateDeviceToken(string $deviceToken): bool
{
    return $deviceToken !== '' && strlen($deviceToken) >= DEVICE_TOKEN_MIN_BYTES;
}

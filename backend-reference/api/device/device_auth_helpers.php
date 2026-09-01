<?php
/**
 * Shared helpers for device-token authentication.
 * Copy into your PHP project and adjust bootstrap paths to match your app.
 */

declare(strict_types=1);

const DEVICE_TOKEN_TTL_DAYS = 90;

function deviceAuthJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function deviceAuthRequirePost(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        deviceAuthJson(405, ['success' => false, 'error' => 'method_not_allowed']);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        deviceAuthJson(400, ['success' => false, 'error' => 'invalid_json']);
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

function deviceAuthStartSessionForUser(PDO $pdo, int $userId): void
{
    // Adjust column names to match your users table.
    $stmt = $pdo->prepare('SELECT id, email, username, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        deviceAuthJson(401, ['success' => false, 'error' => 'user_not_found']);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Adjust session keys to match your existing login flow in index.php.
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['role'] = $user['role'] ?? 'citizen';
    $_SESSION['logged_in'] = true;
}

function deviceAuthRequireLoggedInCitizen(PDO $pdo): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || empty($_SESSION['logged_in'])) {
        deviceAuthJson(401, ['success' => false, 'error' => 'not_authenticated']);
    }

    return $userId;
}

function deviceAuthFindActiveToken(PDO $pdo, string $deviceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM citizen_device_tokens
         WHERE device_id = :device_id
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':device_id' => $deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

<?php
require_once __DIR__ . '/../pages/session.php';
require_login('admin');

$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    header('Location: centers.php?error=invalid');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name FROM evacuation_centers WHERE id = ?");
$stmt->execute([$id]);
$center = $stmt->fetch();

if (!$center) {
    header('Location: centers.php?error=notfound');
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM evacuation_centers WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        header('Location: centers.php?blocked=1&reason=registrations');
        exit;
    }
    header('Location: centers.php?error=failed');
    exit;
}

header('Location: centers.php?deleted=1');
exit;
<?php
require_once __DIR__ . '/../../pages/session.php';
require_login('coordinator');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['ok' => true, 'role' => 'coordinator']);

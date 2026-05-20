<?php
require_once 'db.php';

// Aksepter POST med JSON-objekt { subscription: { endpoint, keys: { p256dh, auth }}}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = $_POST;

$sub = null;
if (!empty($data['subscription'])) {
    $sub = $data['subscription'];
} elseif (!empty($data['endpoint'])) {
    $sub = [
        'endpoint' => $data['endpoint'],
        'keys' => [
            'p256dh' => $data['p256dh'] ?? '',
            'auth' => $data['auth'] ?? ''
        ]
    ];
}

if (!$sub || empty($sub['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_subscription']);
    exit;
}

$endpoint = $sub['endpoint'];
$p256dh = $sub['keys']['p256dh'] ?? '';
$auth = $sub['keys']['auth'] ?? '';

try {
    $existing = $pdo->prepare("SELECT id FROM subscriptions WHERE endpoint = ? LIMIT 1");
    $existing->execute([$endpoint]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare("UPDATE subscriptions SET p256dh = ?, auth = ? WHERE id = ?");
        $stmt->execute([$p256dh, $auth, $existingId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO subscriptions (endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$endpoint, $p256dh, $auth]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => 1]);
} catch (Exception $e) {
    error_log('Subscription save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
}

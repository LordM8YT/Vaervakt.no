<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoden er ikke støttet.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$subscription = $data['subscription'] ?? null;
if (!$subscription && !empty($data['endpoint'])) {
    $subscription = [
        'endpoint' => $data['endpoint'],
        'keys' => [
            'p256dh' => $data['p256dh'] ?? '',
            'auth' => $data['auth'] ?? '',
        ],
    ];
}

if (!is_array($subscription) || empty($subscription['endpoint'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ugyldig push-abonnement.']);
    exit;
}

$endpoint = (string) $subscription['endpoint'];
$p256dh = (string) ($subscription['keys']['p256dh'] ?? '');
$auth = (string) ($subscription['keys']['auth'] ?? '');

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS subscriptions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        endpoint TEXT NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_subscriptions_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $existing = $pdo->prepare('SELECT id FROM subscriptions WHERE endpoint = ? LIMIT 1');
    $existing->execute([$endpoint]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare('UPDATE subscriptions SET p256dh = ?, auth = ? WHERE id = ?');
        $stmt->execute([$p256dh, $auth, $existingId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO subscriptions (endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$endpoint, $p256dh, $auth]);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    error_log('subscription api failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Kunne ikke lagre push-abonnement.']);
}

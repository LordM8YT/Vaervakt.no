<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

function vv_visit_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL,
            path VARCHAR(255) NOT NULL DEFAULT '/',
            referrer VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_site_visits_created_at (created_at),
            KEY idx_site_visits_visitor_created (visitor_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_visit_client_ip(): string
{
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        $value = trim((string) ($_SERVER[$header] ?? ''));
        if ($value === '') {
            continue;
        }
        $first = trim(explode(',', $value)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return 'unknown';
}

function vv_visit_clean_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }

    $parts = parse_url($path);
    $clean = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $path;
    if ($clean === '' || $clean[0] !== '/') {
        $clean = '/' . $clean;
    }

    return substr($clean, 0, 255);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$path = vv_visit_clean_path((string) ($input['path'] ?? '/'));
if (strpos($path, '/admin') === 0) {
    http_response_code(204);
    exit;
}

try {
    vv_visit_ensure_table($pdo);

    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $salt = DB_PASS !== '' ? DB_PASS : DB_NAME;
    $visitorHash = hash('sha256', vv_visit_client_ip() . '|' . $userAgent . '|' . $salt);
    $userAgentHash = hash('sha256', $userAgent . '|' . $salt);
    $referrer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);

    $recent = $pdo->prepare('SELECT created_at FROM site_visits WHERE visitor_hash = ? AND path = ? ORDER BY created_at DESC LIMIT 1');
    $recent->execute([$visitorHash, $path]);
    $lastSeen = $recent->fetchColumn();
    if ($lastSeen !== false && strtotime((string) $lastSeen) >= time() - 300) {
        http_response_code(204);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO site_visits (visitor_hash, user_agent_hash, path, referrer, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$visitorHash, $userAgentHash, $path, $referrer !== '' ? $referrer : null]);

    http_response_code(204);
} catch (Throwable $error) {
    error_log('visit tracking failed: ' . $error->getMessage());
    http_response_code(204);
}

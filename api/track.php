<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_track_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_hash CHAR(64) NOT NULL,
            path VARCHAR(180) NOT NULL DEFAULT '/',
            viewport VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_visits_created (created_at),
            KEY idx_visits_visitor (visitor_hash, created_at),
            KEY idx_visits_path (path, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_track_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        $ip = trim(explode(',', $candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function vv_track_visitor_hash(): string
{
    $secret = vv_env_first(['VISIT_HASH_SECRET', 'ADMIN_PASSWORD_HASH', 'VAPID_PRIVATE_KEY'], DB_PASS);
    if ($secret === '') {
        $secret = DB_NAME . '|vaervakt-visit-secret';
    }

    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180);
    $dailyBucket = gmdate('Y-m-d');
    return hash_hmac('sha256', vv_track_client_ip() . '|' . $agent . '|' . $dailyBucket, $secret);
}

function vv_track_clean_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }
    $path = strtok($path, "?#") ?: '/';
    return vv_limit($path, 180);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        vv_error('Metoden er ikke støttet.', 405);
    }

    $pdo = vv_db();
    vv_track_table($pdo);

    $input = vv_request_body();
    $path = vv_track_clean_path((string) ($input['path'] ?? $_SERVER['HTTP_REFERER'] ?? '/'));
    $viewport = vv_limit(trim((string) ($input['viewport'] ?? '')), 32);

    $stmt = $pdo->prepare('INSERT INTO site_visits (visitor_hash, path, viewport) VALUES (?, ?, ?)');
    $stmt->execute([vv_track_visitor_hash(), $path, $viewport !== '' ? $viewport : null]);

    vv_json(['success' => true]);
} catch (Throwable $error) {
    error_log('track failed: ' . $error->getMessage());
    vv_error('Kunne ikke lagre besøk akkurat nå.', 500);
}

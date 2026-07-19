<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_public_rate_limits_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_action_rate_limits (
            scope VARCHAR(48) NOT NULL,
            client_hash CHAR(64) NOT NULL,
            window_started_at DATETIME NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (scope, client_hash),
            KEY idx_public_rate_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function vv_public_rate_limit_secret(): string
{
    $secret = vv_env_first(
        ['VISIT_HASH_SECRET', 'ADMIN_PASSWORD_HASH', 'VAPID_PRIVATE_KEY'],
        DB_PASS
    );

    return $secret !== '' ? $secret : DB_NAME . '|vaervakt-public-rate-secret';
}

function vv_public_client_fingerprint(): string
{
    $ip = trim((string) (
        $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown'
    ));
    $agent = vv_limit((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 180);
    return $ip . '|' . $agent;
}

function vv_public_rate_client_hash(string $scope): string
{
    return hash_hmac(
        'sha256',
        vv_public_client_fingerprint() . '|' . $scope,
        vv_public_rate_limit_secret()
    );
}

function vv_enforce_public_rate_limit(
    PDO $pdo,
    string $scope,
    int $limit,
    int $windowMinutes,
    string $message
): void {
    if (preg_match('/^[a-z0-9-]{1,48}$/', $scope) !== 1) {
        throw new InvalidArgumentException('Ugyldig rate-limit-scope.');
    }

    $limit = max(1, min(100, $limit));
    $windowMinutes = max(1, min(1440, $windowMinutes));
    $clientHash = vv_public_rate_client_hash($scope);

    $pdo->exec('DELETE FROM public_action_rate_limits WHERE expires_at < NOW()');

    $stmt = $pdo->prepare(
        "INSERT INTO public_action_rate_limits
            (scope, client_hash, window_started_at, hits, expires_at)
        VALUES (?, ?, NOW(), 1, DATE_ADD(NOW(), INTERVAL {$windowMinutes} MINUTE))
        ON DUPLICATE KEY UPDATE
            hits = IF(
                window_started_at < (NOW() - INTERVAL {$windowMinutes} MINUTE),
                1,
                hits + 1
            ),
            window_started_at = IF(
                window_started_at < (NOW() - INTERVAL {$windowMinutes} MINUTE),
                NOW(),
                window_started_at
            ),
            expires_at = DATE_ADD(NOW(), INTERVAL {$windowMinutes} MINUTE)"
    );
    $stmt->execute([$scope, $clientHash]);

    $check = $pdo->prepare(
        'SELECT hits FROM public_action_rate_limits
         WHERE scope = ? AND client_hash = ? LIMIT 1'
    );
    $check->execute([$scope, $clientHash]);

    if ((int) $check->fetchColumn() > $limit) {
        header('Retry-After: ' . ($windowMinutes * 60));
        vv_error($message, 429);
    }
}

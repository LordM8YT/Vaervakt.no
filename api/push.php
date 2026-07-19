<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate-limit-lib.php';

function vv_push_subscriptions_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_secret VARCHAR(128) NOT NULL,
            location_name VARCHAR(140) NOT NULL,
            latitude DECIMAL(9,2) NOT NULL,
            longitude DECIMAL(9,2) NOT NULL,
            alert_frost TINYINT(1) NOT NULL DEFAULT 0,
            alert_strong_wind TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_success_at DATETIME NULL,
            PRIMARY KEY (endpoint_hash),
            KEY idx_push_updated (updated_at),
            KEY idx_push_location (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function vv_push_cleanup(PDO $pdo): void
{
    $retentionDays = max(
        30,
        min(365, (int) vv_env('PUSH_SUBSCRIPTION_RETENTION_DAYS', '90'))
    );
    $pdo->exec(
        "DELETE FROM push_subscriptions
         WHERE COALESCE(last_success_at, updated_at) < (NOW() - INTERVAL {$retentionDays} DAY)"
    );
}

function vv_push_endpoint(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $endpoint = trim($value);
    if ($endpoint === '' || strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $parts = parse_url($endpoint);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $ipHost = trim($host, '[]');
    if (
        $scheme !== 'https'
        || $host === ''
        || !str_contains($host, '.')
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || $host === 'localhost'
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')
    ) {
        return '';
    }

    if (
        filter_var($ipHost, FILTER_VALIDATE_IP) !== false
        && filter_var(
            $ipHost,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false
    ) {
        return '';
    }

    return $endpoint;
}

function vv_push_base64url(mixed $value, int $minLength, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if (
        strlen($value) < $minLength
        || strlen($value) > $maxLength
        || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1
    ) {
        return '';
    }

    return $value;
}

function vv_push_alerts(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $allowed = ['frost', 'strong-wind'];
    $alerts = [];
    foreach ($value as $alert) {
        if (is_string($alert) && in_array($alert, $allowed, true)) {
            $alerts[$alert] = true;
        }
    }
    return array_keys($alerts);
}

function vv_push_unsubscribe(PDO $pdo, array $input): void
{
    $subscription = is_array($input['subscription'] ?? null)
        ? $input['subscription']
        : $input;
    $endpoint = vv_push_endpoint($subscription['endpoint'] ?? '');
    if ($endpoint === '') {
        vv_error('Push-abonnementet mangler et gyldig endepunkt.');
    }

    $delete = $pdo->prepare(
        'DELETE FROM push_subscriptions WHERE endpoint_hash = ?'
    );
    $delete->execute([hash('sha256', $endpoint)]);

    vv_json([
        'success' => true,
        'removed' => $delete->rowCount() > 0,
        'message' => 'Varslene er slått av for denne nettleseren.',
    ]);
}

function vv_push_subscribe(PDO $pdo, array $input): void
{
    if (VAPID_PUBLIC === '') {
        vv_error('Push-varsler er ikke konfigurert på serveren ennå.', 503);
    }

    $subscription = is_array($input['subscription'] ?? null)
        ? $input['subscription']
        : $input;
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $endpoint = vv_push_endpoint($subscription['endpoint'] ?? '');
    $p256dh = vv_push_base64url($keys['p256dh'] ?? $subscription['p256dh'] ?? '', 40, 255);
    $auth = vv_push_base64url($keys['auth'] ?? $subscription['auth'] ?? '', 16, 128);
    $locationNameInput = $input['locationName'] ?? $input['location'] ?? '';
    $locationName = is_string($locationNameInput) ? trim($locationNameInput) : '';
    $lat = vv_float($input['lat'] ?? null);
    $lon = vv_float($input['lon'] ?? null);
    $alerts = vv_push_alerts($input['alerts'] ?? []);

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        vv_error('Push-abonnementet er ugyldig eller ufullstendig.');
    }
    if (
        $locationName === ''
        || vv_len($locationName) > 140
        || preg_match('/[\x00-\x1F\x7F]/u', $locationName) === 1
    ) {
        vv_error('Velg et gyldig sted for varslene.');
    }
    if (
        $lat === null
        || $lon === null
        || $lat < -90
        || $lat > 90
        || $lon < -180
        || $lon > 180
    ) {
        vv_error('Varslingsstedet har ugyldige koordinater.');
    }
    if (!$alerts) {
        vv_error('Velg minst én varseltype.');
    }

    $endpointHash = hash('sha256', $endpoint);
    $existing = $pdo->prepare(
        'SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = ?'
    );
    $existing->execute([$endpointHash]);
    $wasExisting = (int) $existing->fetchColumn() > 0;

    $save = $pdo->prepare(
        'INSERT INTO push_subscriptions
            (endpoint_hash, endpoint, p256dh, auth_secret, location_name, latitude, longitude,
             alert_frost, alert_strong_wind)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh),
            auth_secret = VALUES(auth_secret),
            location_name = VALUES(location_name),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            alert_frost = VALUES(alert_frost),
            alert_strong_wind = VALUES(alert_strong_wind),
            updated_at = NOW()'
    );
    $save->execute([
        $endpointHash,
        $endpoint,
        $p256dh,
        $auth,
        vv_limit($locationName, 140),
        round($lat, 2),
        round($lon, 2),
        in_array('frost', $alerts, true) ? 1 : 0,
        in_array('strong-wind', $alerts, true) ? 1 : 0,
    ]);

    vv_json([
        'success' => true,
        'created' => !$wasExisting,
        'message' => $wasExisting
            ? 'Varselvalgene er oppdatert.'
            : 'Varsler er slått på for det valgte stedet.',
        'alerts' => $alerts,
        'locationName' => vv_limit($locationName, 140),
    ], $wasExisting ? 200 : 201);
}

if (defined('VV_PUSH_LIBRARY_ONLY') && VV_PUSH_LIBRARY_ONLY === true) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vv_error('Metoden støttes ikke.', 405);
}

try {
    $input = vv_request_body();
    $action = strtolower(trim((string) ($input['action'] ?? 'subscribe')));
    $pdo = vv_db();
    vv_push_subscriptions_table($pdo);
    vv_push_cleanup($pdo);

    if ($action === 'unsubscribe') {
        vv_push_unsubscribe($pdo, $input);
    }
    if ($action === 'subscribe' || $action === 'update') {
        vv_public_rate_limits_table($pdo);
        vv_enforce_public_rate_limit(
            $pdo,
            'push-subscription',
            (int) vv_env('PUSH_RATE_LIMIT', '10'),
            (int) vv_env('PUSH_RATE_WINDOW_MINUTES', '60'),
            'Du har endret varselinnstillingene mange ganger. Prøv igjen senere.'
        );
        vv_push_subscribe($pdo, $input);
    }

    vv_error('Ukjent push-handling.');
} catch (Throwable $error) {
    error_log('push subscription failed: ' . $error->getMessage());
    vv_error('Kunne ikke lagre varselinnstillingene akkurat nå.', 500);
}

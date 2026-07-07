<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Oslo');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

function vv_load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        $first = $value[0] ?? '';
        $last = $value[strlen($value) - 1] ?? '';
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

vv_load_env_file(APP_ROOT . DIRECTORY_SEPARATOR . '.env');
vv_load_env_file(dirname(APP_ROOT) . DIRECTORY_SEPARATOR . '.env');

function vv_env(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $value = getenv($key);
    return $value === false || $value === '' ? $default : (string) $value;
}

function vv_env_first(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $value = vv_env($key);
        if ($value !== '') {
            return $value;
        }
    }
    return $default;
}

function vv_clean_header_secret(string $value, string $headerName): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return trim((string) preg_replace('/^' . preg_quote($headerName, '/') . '\s*:\s*/i', '', $value));
}

function vv_database_url(): array
{
    $url = vv_env_first(['DATABASE_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']);
    if ($url === '') {
        return [];
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return [];
    }

    return [
        'host' => (string) ($parts['host'] ?? ''),
        'name' => ltrim((string) ($parts['path'] ?? ''), '/'),
        'user' => rawurldecode((string) ($parts['user'] ?? '')),
        'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
        'port' => isset($parts['port']) ? (string) $parts['port'] : '',
    ];
}

$databaseUrl = vv_database_url();

define('DB_HOST', vv_env_first(['DB_HOST', 'MYSQL_HOST', 'MYSQLHOST', 'DATABASE_HOST'], $databaseUrl['host'] ?? ''));
define('DB_NAME', vv_env_first(['DB_NAME', 'MYSQL_DATABASE', 'MYSQL_DB', 'DATABASE_NAME'], $databaseUrl['name'] ?? ''));
define('DB_USER', vv_env_first(['DB_USER', 'MYSQL_USER', 'DATABASE_USER'], $databaseUrl['user'] ?? ''));
define('DB_PASS', vv_env_first(['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_PASS', 'DATABASE_PASSWORD'], $databaseUrl['pass'] ?? ''));
define('DB_PORT', vv_env_first(['DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT'], $databaseUrl['port'] ?? ''));
define('YR_BATH_API_KEY', vv_clean_header_secret(vv_env_first(['YR_BATH_API_KEY', 'YR_BADETEMP_API_KEY', 'YR_WATER_TEMPERATURE_API_KEY']), 'apikey'));
define('SUPPORT_URL', vv_env('SUPPORT_URL', 'https://betal.vipps.no/opy01u'));
define('SUPPORT_LABEL', vv_env('SUPPORT_LABEL', 'Støtt med Vipps'));
define('VAPID_PUBLIC', vv_env_first(['VAPID_PUBLIC', 'VAPID_PUBLIC_KEY', 'VAPID_PUBLICKEY', 'WEB_PUSH_PUBLIC_KEY']));

function vv_send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

vv_send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function vv_json(array $data, int $status = 200, string $cacheControl = 'no-store'): void
{
    http_response_code($status);
    vv_send_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . $cacheControl);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vv_error(string $message, int $status = 400): void
{
    vv_json(['success' => false, 'message' => $message], $status);
}

function vv_request_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

function vv_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
        throw new RuntimeException('Database is not configured.');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    if (DB_PORT !== '') {
        $dsn .= ';port=' . DB_PORT;
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function vv_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function vv_limit(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function vv_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function vv_float($value): ?float
{
    $result = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    return $result === null ? null : (float) $result;
}

function vv_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function vv_relative_time(?string $timestamp): string
{
    if (!$timestamp) {
        return 'Nå nettopp';
    }

    try {
        $created = new DateTime($timestamp);
        $seconds = max(0, time() - $created->getTimestamp());
    } catch (Throwable) {
        return 'Nylig';
    }

    if ($seconds < 45) return 'Nå nettopp';
    if ($seconds < 3600) return floor($seconds / 60) . ' min siden';
    if ($seconds < 86400) return floor($seconds / 3600) . ' t siden';
    if ($seconds < 604800) return floor($seconds / 86400) . ' d siden';
    return $created->format('d.m H:i');
}

function vv_location_terms(string $location): array
{
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($location, 'UTF-8') : strtolower($location);
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (strlen($part) < 3 || in_array($part, ['norge', 'norway'], true)) {
            continue;
        }
        $terms[$part] = true;
        if (count($terms) >= 4) {
            break;
        }
    }
    return array_keys($terms);
}

function vv_http_get_json(string $url, array $headers = [], int $timeout = 8): array
{
    $headers[] = 'User-Agent: Vaervakt.no/2.0 (' . vv_env('VAPID_SUBJECT', 'mailto:patrick@vaarvakt.no') . ')';
    $headers[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status >= 400) {
            throw new RuntimeException('HTTP request failed: ' . ($error ?: $status));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed.');
        }
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        throw new RuntimeException('HTTP response was not JSON.');
    }
    return $json;
}

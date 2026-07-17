<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_reports_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            weather_condition VARCHAR(80) NOT NULL,
            location VARCHAR(140) NOT NULL,
            temperature DECIMAL(5,2) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_reports_created (created_at),
            KEY idx_reports_location (location),
            KEY idx_reports_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_reports_rate_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_report_rate_limits (
            client_hash CHAR(64) NOT NULL,
            window_started_at DATETIME NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (client_hash),
            KEY idx_report_rate_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_reports_cleanup(PDO $pdo): void
{
    $retentionHours = max(24, min(720, (int) vv_env('REPORT_RETENTION_HOURS', '168')));
    $pdo->exec("DELETE FROM weather_reports WHERE created_at < (NOW() - INTERVAL {$retentionHours} HOUR)");
    $pdo->exec('DELETE FROM weather_report_rate_limits WHERE expires_at <= NOW()');
    $pdo->exec(
        'UPDATE weather_reports
         SET latitude = ROUND(latitude, 2), longitude = ROUND(longitude, 2)
         WHERE (latitude IS NOT NULL AND latitude <> ROUND(latitude, 2))
            OR (longitude IS NOT NULL AND longitude <> ROUND(longitude, 2))'
    );
}

function vv_reports_allowed_conditions(): array
{
    return ['Sol / Klart', 'Delvis skyet', 'Skyet', 'Regn', 'Snø', 'Torden'];
}

function vv_reports_has_control_characters(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
}

function vv_reports_client_hash(): string
{
    $ip = trim((string) (
        $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown'
    ));
    $agent = vv_limit((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 180);
    $secret = vv_env_first(
        ['VISIT_HASH_SECRET', 'ADMIN_PASSWORD_HASH', 'VAPID_PRIVATE_KEY'],
        DB_PASS
    );
    if ($secret === '') {
        $secret = DB_NAME . '|vaervakt-report-secret';
    }

    $hourBucket = gmdate('Y-m-d-H');
    return hash_hmac('sha256', $ip . '|' . $agent . '|' . $hourBucket, $secret);
}

function vv_reports_enforce_rate_limit(PDO $pdo): void
{
    $clientHash = vv_reports_client_hash();
    $limit = max(1, min(30, (int) vv_env('REPORT_RATE_LIMIT', '6')));
    $windowMinutes = max(1, min(60, (int) vv_env('REPORT_RATE_WINDOW_MINUTES', '10')));

    $stmt = $pdo->prepare("
        INSERT INTO weather_report_rate_limits
            (client_hash, window_started_at, hits, expires_at)
        VALUES (?, NOW(), 1, DATE_ADD(NOW(), INTERVAL 60 MINUTE))
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
            expires_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
    ");
    $stmt->execute([$clientHash]);

    $check = $pdo->prepare(
        'SELECT hits FROM weather_report_rate_limits WHERE client_hash = ? LIMIT 1'
    );
    $check->execute([$clientHash]);
    if ((int) $check->fetchColumn() > $limit) {
        header('Retry-After: ' . ($windowMinutes * 60));
        vv_error("Du har sendt mange rapporter på kort tid. Prøv igjen om {$windowMinutes} minutter.", 429);
    }
}

function vv_report_icon(string $condition): string
{
    $lower = function_exists('mb_strtolower')
        ? mb_strtolower($condition, 'UTF-8')
        : strtolower($condition);
    if (str_contains($lower, 'snø') || str_contains($lower, 'sludd')) return '❄️';
    if (str_contains($lower, 'regn') || str_contains($lower, 'byge')) return '🌧️';
    if (str_contains($lower, 'storm') || str_contains($lower, 'vind')) return '⛈️';
    if (str_contains($lower, 'tåke')) return '🌫️';
    if (str_contains($lower, 'sky')) return '☁️';
    return '☀️';
}

function vv_reports_get(PDO $pdo): void
{
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);
    $radiusKm = max(1, min(100, (float) ($_GET['radiusKm'] ?? 25)));
    $location = trim((string) ($_GET['location'] ?? $_GET['q'] ?? ''));
    $requestedMaxAgeHours = vv_float($_GET['maxAgeHours'] ?? null);
    $maxAgeHours = (int) max(1, min(168, round($requestedMaxAgeHours ?? 24)));
    $terms = vv_location_terms($location);

    $scopeClauses = [];
    $scopeParams = [];
    $hasCoordinates =
        $lat !== null && $lon !== null
        && $lat >= -90 && $lat <= 90
        && $lon >= -180 && $lon <= 180;

    if ($hasCoordinates) {
        $scopeClauses[] = '(latitude IS NOT NULL AND longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(latitude)))))) <= ?)';
        array_push($scopeParams, $lat, $lon, $lat, $radiusKm);
    }

    foreach ($terms as $term) {
        $scopeClauses[] = $hasCoordinates
            ? '((latitude IS NULL OR longitude IS NULL) AND LOWER(location) LIKE ?)'
            : 'LOWER(location) LIKE ?';
        $scopeParams[] = '%' . $term . '%';
    }

    $clauses = ["created_at >= (NOW() - INTERVAL {$maxAgeHours} HOUR)"];
    $params = [];
    if ($scopeClauses) {
        $clauses[] = '(' . implode(' OR ', $scopeClauses) . ')';
        $params = $scopeParams;
    }
    $where = ' WHERE ' . implode(' AND ', $clauses);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM weather_reports{$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM weather_reports{$where} ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $reports = array_map(static function (array $row) use ($hasCoordinates, $lat, $lon): array {
        $condition = (string) ($row['weather_condition'] ?? '');
        $reportLat = $row['latitude'] !== null ? (float) $row['latitude'] : null;
        $reportLon = $row['longitude'] !== null ? (float) $row['longitude'] : null;
        $distanceKm = $hasCoordinates && $reportLat !== null && $reportLon !== null
            ? round(vv_distance_km((float) $lat, (float) $lon, $reportLat, $reportLon), 1)
            : null;

        return [
            'id' => (int) $row['id'],
            'icon' => vv_report_icon($condition),
            'time' => vv_relative_time((string) ($row['created_at'] ?? '')),
            'reporter' => (string) ($row['username'] ?? 'Anonym'),
            'condition' => $condition,
            'location' => (string) ($row['location'] ?? ''),
            'temp' => round((float) ($row['temperature'] ?? 0), 1),
            'distanceKm' => $distanceKm,
        ];
    }, $rows);

    vv_json([
        'success' => true,
        'filtered' => true,
        'radiusKm' => $hasCoordinates ? $radiusKm : null,
        'total' => $total,
        'displayed' => count($reports),
        'freshness' => [
            'mode' => 'fresh',
            'maxAgeHours' => $maxAgeHours,
            'maxAgeDays' => round($maxAgeHours / 24, 2),
            'hiddenOldReports' => 0,
        ],
        'reports' => $reports,
    ]);
}

function vv_reports_post(PDO $pdo): void
{
    $input = vv_request_body();
    $usernameInput = $input['username'] ?? $input['user'] ?? '';
    $conditionInput = $input['condition'] ?? '';
    $locationInput = $input['location'] ?? '';
    if (!is_string($usernameInput) || !is_string($conditionInput) || !is_string($locationInput)) {
        vv_error('Visningsnavn, værtype og sted må være tekst.');
    }

    $username = trim($usernameInput) ?: 'Anonym';
    $condition = trim($conditionInput);
    $location = trim($locationInput);
    $temperature = vv_float($input['temperature'] ?? $input['temp'] ?? null);
    $lat = vv_float($input['lat'] ?? null);
    $lon = vv_float($input['lon'] ?? null);

    if ($condition === '' || $location === '' || $temperature === null) {
        vv_error('Fyll inn værtype, sted og temperatur.');
    }
    if (vv_len($username) > 40 || vv_len($condition) > 80 || vv_len($location) > 140) {
        vv_error('Visningsnavn, værtype eller sted er for langt.');
    }
    if (vv_reports_has_control_characters($username)
        || vv_reports_has_control_characters($condition)
        || vv_reports_has_control_characters($location)) {
        vv_error('Rapporten inneholder ugyldige tegn.');
    }
    if (!in_array($condition, vv_reports_allowed_conditions(), true)) {
        vv_error('Velg en gyldig værtype.');
    }
    if ($temperature < -60 || $temperature > 60) {
        vv_error('Temperaturen må være mellom -60 og 60 grader.');
    }
    if (($lat === null) !== ($lon === null)) {
        vv_error('Både breddegrad og lengdegrad må sendes sammen.');
    }
    if (($lat !== null && ($lat < -90 || $lat > 90))
        || ($lon !== null && ($lon < -180 || $lon > 180))) {
        vv_error('Koordinatene ser ikke gyldige ut.');
    }

    vv_reports_enforce_rate_limit($pdo);
    $lat = $lat !== null ? round($lat, 2) : null;
    $lon = $lon !== null ? round($lon, 2) : null;

    $dupe = $pdo->prepare("
        SELECT id FROM weather_reports
        WHERE username = ? AND weather_condition = ? AND location = ?
          AND ABS(temperature - ?) < 0.1
          AND created_at >= (NOW() - INTERVAL 20 SECOND)
        LIMIT 1
    ");
    $dupe->execute([$username, $condition, $location, $temperature]);
    if ($dupe->fetchColumn()) {
        vv_json([
            'success' => true,
            'duplicate' => true,
            'message' => 'Rapporten var allerede sendt.',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO weather_reports
            (username, weather_condition, location, temperature, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, $condition, $location, $temperature, $lat, $lon]);

    vv_json([
        'success' => true,
        'message' => 'Rapporten er sendt og slettes automatisk etter 7 dager.',
        'reportId' => (int) $pdo->lastInsertId(),
    ]);
}

try {
    $pdo = vv_db();
    vv_reports_table($pdo);
    vv_reports_rate_table($pdo);
    vv_reports_cleanup($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vv_reports_get($pdo);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vv_reports_post($pdo);
    }
    vv_error('Metoden er ikke støttet.', 405);
} catch (Throwable $error) {
    error_log('reports failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere rapporter akkurat nå.', 500);
}

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

function vv_report_icon(string $condition): string
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower($condition, 'UTF-8') : strtolower($condition);
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
    $terms = vv_location_terms($location);

    $clauses = [];
    $params = [];
    $filtered = false;

    if ($lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
        $clauses[] = '(latitude IS NOT NULL AND longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(latitude)))))) <= ?)';
        array_push($params, $lat, $lon, $lat, $radiusKm);
        $filtered = true;
    }

    foreach ($terms as $term) {
        $clauses[] = 'LOWER(location) LIKE ?';
        $params[] = '%' . $term . '%';
        $filtered = true;
    }

    $where = $clauses ? ' WHERE ' . implode(' OR ', $clauses) : '';
    $stmt = $pdo->prepare("SELECT * FROM weather_reports{$where} ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $reports = array_map(static function (array $row): array {
        $condition = (string) ($row['weather_condition'] ?? '');
        return [
            'id' => (int) $row['id'],
            'icon' => vv_report_icon($condition),
            'time' => vv_relative_time((string) ($row['created_at'] ?? '')),
            'reporter' => (string) ($row['username'] ?? 'Anonym'),
            'condition' => $condition,
            'location' => (string) ($row['location'] ?? ''),
            'temp' => round((float) ($row['temperature'] ?? 0)),
            'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'lon' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
        ];
    }, $rows);

    vv_json([
        'success' => true,
        'filtered' => $filtered,
        'radiusKm' => $filtered ? $radiusKm : null,
        'reports' => $reports,
    ]);
}

function vv_reports_post(PDO $pdo): void
{
    $input = vv_request_body();
    $username = trim((string) ($input['username'] ?? $input['user'] ?? ''));
    $condition = trim((string) ($input['condition'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $temperature = vv_float($input['temperature'] ?? $input['temp'] ?? null);
    $lat = vv_float($input['lat'] ?? null);
    $lon = vv_float($input['lon'] ?? null);

    if ($username === '' || $condition === '' || $location === '' || $temperature === null) {
        vv_error('Fyll inn navn, værtype, sted og temperatur.');
    }
    if (($lat !== null && ($lat < -90 || $lat > 90)) || ($lon !== null && ($lon < -180 || $lon > 180))) {
        vv_error('Koordinatene ser ikke gyldige ut.');
    }

    $username = vv_limit($username, 80);
    $condition = vv_limit($condition, 80);
    $location = vv_limit($location, 140);

    $dupe = $pdo->prepare("
        SELECT id FROM weather_reports
        WHERE username = ? AND weather_condition = ? AND location = ? AND ABS(temperature - ?) < 0.1
          AND created_at >= (NOW() - INTERVAL 20 SECOND)
        LIMIT 1
    ");
    $dupe->execute([$username, $condition, $location, $temperature]);
    if ($dupe->fetchColumn()) {
        vv_json(['success' => true, 'duplicate' => true, 'message' => 'Rapporten var allerede sendt.']);
    }

    $stmt = $pdo->prepare('INSERT INTO weather_reports (username, weather_condition, location, temperature, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$username, $condition, $location, $temperature, $lat, $lon]);

    vv_json([
        'success' => true,
        'message' => 'Rapporten er sendt.',
        'reportId' => (int) $pdo->lastInsertId(),
    ]);
}

try {
    $pdo = vv_db();
    vv_reports_table($pdo);
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

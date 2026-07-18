<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/report-lib.php';

function vv_reports_allowed_conditions(): array
{
    return ['Sol / Klart', 'Delvis skyet', 'Skyet', 'Regn', 'Snø', 'Torden'];
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
    $requestedMaxAgeDays = vv_float($_GET['maxAgeDays'] ?? null);
    $maxAgeHours = $requestedMaxAgeHours
        ?? ($requestedMaxAgeDays !== null ? $requestedMaxAgeDays * 24 : 24);
    // Rapporter kan beholdes lenger for moderering, men skal aldri være
    // offentlig tilgjengelige i mer enn sju dager.
    $maxAgeHours = (int) max(1, min(168, round($maxAgeHours)));
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

    $clauses = [
        "moderation_status IN ('visible', 'review')",
        "created_at >= (NOW() - INTERVAL {$maxAgeHours} HOUR)",
    ];
    $params = [];
    if ($scopeClauses) {
        $clauses[] = '(' . implode(' OR ', $scopeClauses) . ')';
        $params = $scopeParams;
    }
    $where = ' WHERE ' . implode(' AND ', $clauses);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM weather_reports{$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT * FROM weather_reports{$where} ORDER BY created_at DESC LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $oldClauses = [
        "moderation_status IN ('visible', 'review')",
        "created_at < (NOW() - INTERVAL {$maxAgeHours} HOUR)",
    ];
    $oldParams = [];
    if ($scopeClauses) {
        $oldClauses[] = '(' . implode(' OR ', $scopeClauses) . ')';
        $oldParams = $scopeParams;
    }
    $oldStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM weather_reports WHERE ' . implode(' AND ', $oldClauses)
    );
    $oldStmt->execute($oldParams);
    $hiddenOldReports = (int) $oldStmt->fetchColumn();

    $reports = array_map(
        static function (array $row) use ($hasCoordinates, $lat, $lon): array {
            $condition = (string) ($row['weather_condition'] ?? '');
            $reportLat = $row['latitude'] !== null ? (float) $row['latitude'] : null;
            $reportLon = $row['longitude'] !== null ? (float) $row['longitude'] : null;
            $distanceKm = $hasCoordinates && $reportLat !== null && $reportLon !== null
                ? round(vv_distance_km(
                    (float) $lat,
                    (float) $lon,
                    $reportLat,
                    $reportLon
                ), 1)
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
        },
        $rows
    );

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
            'hiddenOldReports' => $hiddenOldReports,
        ],
        'reports' => $reports,
    ]);
}

function vv_reports_post(PDO $pdo, array $input): void
{
    $usernameInput = $input['username'] ?? $input['user'] ?? '';
    $conditionInput = $input['condition'] ?? '';
    $locationInput = $input['location'] ?? '';
    if (!is_string($usernameInput)
        || !is_string($conditionInput)
        || !is_string($locationInput)) {
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
        'message' => 'Rapporten er sendt og vises offentlig i opptil 7 dager.',
        'reportId' => (int) $pdo->lastInsertId(),
    ]);
}

try {
    $pdo = vv_db();
    vv_reports_table($pdo);
    vv_reports_cleanup($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vv_reports_get($pdo);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = vv_request_body();
        if (($input['action'] ?? '') === 'flag') {
            vv_json([
                'success' => true,
                ...vv_reports_flag($pdo, $input),
            ]);
        }
        vv_reports_post($pdo, $input);
    }
    vv_error('Metoden er ikke støttet.', 405);
} catch (Throwable $error) {
    error_log('reports failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere rapporter akkurat nå.', 500);
}

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

function vv_table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

function vv_table_columns(PDO $pdo, string $name): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$name]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function vv_weather_emoji(string $type): string
{
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($type, 'UTF-8') : strtolower($type);
    if (strpos($normalized, 'snø') !== false || strpos($normalized, 'sno') !== false) return '❄️';
    if (strpos($normalized, 'regn') !== false || strpos($normalized, 'rain') !== false || strpos($normalized, 'byge') !== false) return '🌧️';
    if (strpos($normalized, 'vind') !== false || strpos($normalized, 'storm') !== false) return '⛈️';
    if (strpos($normalized, 'tåke') !== false || strpos($normalized, 'taake') !== false || strpos($normalized, 'fog') !== false) return '🌫️';
    if (strpos($normalized, 'sky') !== false || strpos($normalized, 'cloud') !== false) return '☁️';
    return '☀️';
}

function vv_relative_time(?string $timestamp): string
{
    if (!$timestamp) return 'Nå nettopp';

    try {
        $created = new DateTime($timestamp);
        $seconds = max(0, time() - $created->getTimestamp());
    } catch (Throwable $error) {
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
        if (strlen($part) < 3 || in_array($part, ['norge', 'norway'], true)) continue;
        $terms[$part] = true;
        if (count($terms) >= 4) break;
    }
    return array_keys($terms);
}

function vv_report_filter(array $cols, bool $hasLocationFilter, ?float $lat, ?float $lon, float $radiusKm, array $terms): array
{
    $clauses = [];
    $params = [];
    if ($hasLocationFilter && in_array('latitude', $cols, true) && in_array('longitude', $cols, true)) {
        $clauses[] = '(latitude IS NOT NULL AND longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(latitude)))))) <= ?)';
        array_push($params, $lat, $lon, $lat, $radiusKm);
    }

    if ($terms !== []) {
        $textClauses = [];
        foreach ($terms as $term) {
            $textClauses[] = 'LOWER(location) LIKE ?';
            $params[] = '%' . $term . '%';
        }
        $clauses[] = '(' . implode(' OR ', $textClauses) . ')';
    }

    if ($clauses === []) return ['', [], false];
    return [' WHERE ' . implode(' OR ', $clauses), $params, true];
}

try {
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 15)));
    $filterLat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $filterLon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $radiusKm = max(1, min(100, (float) ($_GET['radiusKm'] ?? 25)));
    $locationQuery = trim((string) ($_GET['location'] ?? $_GET['q'] ?? ''));
    $locationTerms = vv_location_terms($locationQuery);
    $hasLocationFilter = $filterLat !== null && $filterLon !== null && $filterLat >= -90 && $filterLat <= 90 && $filterLon >= -180 && $filterLon <= 180;
    $rows = [];
    $table = null;
    $filtered = false;

    if (vv_table_exists($pdo, 'weather_reports')) {
        $table = 'weather_reports';
        $cols = vv_table_columns($pdo, 'weather_reports');
        $select = ['username', 'weather_condition', 'location', 'temperature', 'created_at'];
        if (in_array('latitude', $cols, true)) $select[] = 'latitude';
        if (in_array('longitude', $cols, true)) $select[] = 'longitude';
        [$where, $params, $filtered] = vv_report_filter($cols, $hasLocationFilter, $filterLat, $filterLon, $radiusKm, $locationTerms);
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM weather_reports' . $where . ' ORDER BY created_at DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (vv_table_exists($pdo, 'reports')) {
        $table = 'reports';
        $cols = vv_table_columns($pdo, 'reports');
        $select = ['reporter_name AS username', 'conditions AS weather_condition', 'location', 'temperature_c AS temperature', 'created_at'];
        if (in_array('latitude', $cols, true)) $select[] = 'latitude';
        if (in_array('longitude', $cols, true)) $select[] = 'longitude';
        [$where, $params, $filtered] = vv_report_filter($cols, $hasLocationFilter, $filterLat, $filterLon, $radiusKm, $locationTerms);
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM reports' . $where . ' ORDER BY created_at DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $reports = array_map(static function (array $row): array {
        $condition = trim((string) ($row['weather_condition'] ?? ''));
        $location = trim((string) ($row['location'] ?? ''));
        $username = trim((string) ($row['username'] ?? 'Anonym'));

        return [
            'icon' => vv_weather_emoji($condition),
            'time' => vv_relative_time($row['created_at'] ?? null),
            'reporter' => $username . ($location !== '' ? ' i ' . $location : ''),
            'condition' => $condition !== '' ? $condition : 'Ukjent',
            'temp' => round((float) ($row['temperature'] ?? 0)),
            'lat' => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'lon' => isset($row['longitude']) ? (float) $row['longitude'] : null,
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'source' => $table,
        'filtered' => $filtered,
        'radiusKm' => $filtered ? $radiusKm : null,
        'locationTerms' => $filtered ? $locationTerms : [],
        'reports' => $reports,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('reports api failed: ' . $error->getMessage());
    echo json_encode([
        'success' => false,
        'reports' => [],
        'message' => 'Kunne ikke hente observasjoner akkurat nå.',
    ], JSON_UNESCAPED_UNICODE);
}

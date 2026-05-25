<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

function vv_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

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

function vv_recent_duplicate_exists(
    PDO $pdo,
    string $table,
    string $userColumn,
    string $conditionColumn,
    string $locationColumn,
    string $temperatureColumn,
    string $user,
    string $condition,
    string $location,
    float $temperature
): bool {
    $sql = "SELECT COUNT(*) FROM {$table}
        WHERE {$userColumn} = ?
          AND {$conditionColumn} = ?
          AND {$locationColumn} = ?
          AND ABS({$temperatureColumn} - ?) < 0.01
          AND created_at >= (NOW() - INTERVAL 20 SECOND)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user, $condition, $location, $temperature]);
    return (int) $stmt->fetchColumn() > 0;
}

function vv_success_response(string $user, string $condition, string $location, float $temp, $lat, $lon, bool $duplicate = false): void
{
    echo json_encode([
        'success' => true,
        'duplicate' => $duplicate,
        'report' => [
            'icon' => vv_weather_emoji($condition),
            'time' => 'Nå nettopp',
            'reporter' => $user . ' i ' . $location,
            'condition' => $condition,
            'temp' => round($temp),
            'lat' => $lat,
            'lon' => $lon,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vv_json_error('Metoden er ikke støttet.', 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$user = trim((string) ($input['user'] ?? ''));
$condition = trim((string) ($input['condition'] ?? $input['weather_type'] ?? ''));
$location = trim((string) ($input['location'] ?? $input['loc'] ?? ''));
$temp = filter_var($input['temp'] ?? null, FILTER_VALIDATE_FLOAT);
$lat = filter_var($input['lat'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
$lon = filter_var($input['lon'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

if ($user === '' || $condition === '' || $location === '' || $temp === false) {
    vv_json_error('Fyll inn navn, sted, temperatur og værtype.');
}

$user = substr($user, 0, 50);
$condition = substr($condition, 0, 50);
$location = substr($location, 0, 100);

try {
    if (vv_table_exists($pdo, 'weather_reports')) {
        $cols = vv_table_columns($pdo, 'weather_reports');
        if (vv_recent_duplicate_exists($pdo, 'weather_reports', 'username', 'weather_condition', 'location', 'temperature', $user, $condition, $location, (float) $temp)) {
            vv_success_response($user, $condition, $location, (float) $temp, $lat, $lon, true);
            exit;
        }

        $insertCols = ['username', 'weather_condition', 'location', 'temperature'];
        $values = [$user, $condition, $location, $temp];
        if ($lat !== null && in_array('latitude', $cols, true)) {
            $insertCols[] = 'latitude';
            $values[] = $lat;
        }
        if ($lon !== null && in_array('longitude', $cols, true)) {
            $insertCols[] = 'longitude';
            $values[] = $lon;
        }
        $stmt = $pdo->prepare('INSERT INTO weather_reports (' . implode(', ', $insertCols) . ', created_at) VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ', NOW())');
        $stmt->execute($values);
    } elseif (vv_table_exists($pdo, 'reports')) {
        $cols = vv_table_columns($pdo, 'reports');
        if (vv_recent_duplicate_exists($pdo, 'reports', 'reporter_name', 'conditions', 'location', 'temperature_c', $user, $condition, $location, (float) $temp)) {
            vv_success_response($user, $condition, $location, (float) $temp, $lat, $lon, true);
            exit;
        }

        $insertCols = ['reporter_name', 'conditions', 'location', 'temperature_c'];
        $values = [$user, $condition, $location, $temp];
        if ($lat !== null && in_array('latitude', $cols, true)) {
            $insertCols[] = 'latitude';
            $values[] = $lat;
        }
        if ($lon !== null && in_array('longitude', $cols, true)) {
            $insertCols[] = 'longitude';
            $values[] = $lon;
        }
        $stmt = $pdo->prepare('INSERT INTO reports (' . implode(', ', $insertCols) . ', created_at) VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ', NOW())');
        $stmt->execute($values);
    } else {
        throw new RuntimeException('No supported reports table found');
    }

    vv_success_response($user, $condition, $location, (float) $temp, $lat, $lon);
} catch (Throwable $error) {
    error_log('report api failed: ' . $error->getMessage());
    vv_json_error('Kunne ikke lagre rapporten akkurat nå.', 500);
}

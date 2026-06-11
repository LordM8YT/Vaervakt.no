<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

function vv_bathing_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function vv_bathing_parse_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $normalized = str_replace(',', '.', trim((string) $value));
    $number = filter_var($normalized, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    return $number === null ? null : (float) $number;
}

function vv_bathing_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function vv_bathing_substr(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function vv_bathing_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bathing_place_suggestions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_name VARCHAR(120) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            reporter VARCHAR(120) NULL,
            note VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_bathing_place_status_created (status, created_at),
            KEY idx_bathing_place_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_bathing_duplicate_exists(PDO $pdo, string $placeName, float $lat, float $lon): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM bathing_place_suggestions
        WHERE LOWER(place_name) = LOWER(?)
          AND ABS(latitude - ?) < 0.0005
          AND ABS(longitude - ?) < 0.0005
          AND created_at >= (NOW() - INTERVAL 10 MINUTE)
    ");
    $stmt->execute([$placeName, $lat, $lon]);
    return (int) $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vv_bathing_json_error('Metoden er ikke støttet.', 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$placeName = trim((string) ($input['place_name'] ?? ''));
$lat = vv_bathing_parse_float($input['latitude'] ?? null);
$lon = vv_bathing_parse_float($input['longitude'] ?? null);
$reporter = trim((string) ($input['reporter'] ?? ''));
$note = trim((string) ($input['note'] ?? ''));

if ($placeName === '' || vv_bathing_strlen($placeName) < 2) {
    vv_bathing_json_error('Skriv inn navn på badeplassen.');
}

if ($lat === null || $lat < -90 || $lat > 90 || $lon === null || $lon < -180 || $lon > 180) {
    vv_bathing_json_error('Koordinatene ser ikke gyldige ut.');
}

$placeName = vv_bathing_substr($placeName, 120);
$reporter = $reporter === '' ? null : vv_bathing_substr($reporter, 120);
$note = $note === '' ? null : vv_bathing_substr($note, 500);

try {
    vv_bathing_ensure_table($pdo);

    if (vv_bathing_duplicate_exists($pdo, $placeName, $lat, $lon)) {
        echo json_encode([
            'success' => true,
            'duplicate' => true,
            'message' => 'Denne badeplassen ligger allerede i køen.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO bathing_place_suggestions (place_name, latitude, longitude, reporter, note, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$placeName, $lat, $lon, $reporter, $note]);

    echo json_encode([
        'success' => true,
        'message' => 'Badeplassforslaget er sendt til godkjenning.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('bathing place suggestion failed: ' . $error->getMessage());
    vv_bathing_json_error('Kunne ikke lagre badeplassforslaget akkurat nå.', 500);
}

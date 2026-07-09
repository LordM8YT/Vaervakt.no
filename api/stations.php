<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/station-lib.php';

function vv_stations_latest(PDO $pdo): void
{
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);
    $radiusKm = max(1, min(250, (float) ($_GET['radiusKm'] ?? 50)));
    $location = trim((string) ($_GET['location'] ?? $_GET['q'] ?? ''));
    $terms = vv_location_terms($location);

    $stmt = $pdo->prepare("
        SELECT
            s.*,
            r.id AS reading_id,
            r.temperature,
            r.humidity,
            r.pressure,
            r.rain_rate,
            r.rain_total,
            r.wind_speed,
            r.wind_direction,
            r.observed_at,
            r.received_at
        FROM weather_stations s
        LEFT JOIN station_readings r ON r.id = (
            SELECT sr.id
            FROM station_readings sr
            WHERE sr.station_id = s.id
            ORDER BY sr.observed_at DESC, sr.id DESC
            LIMIT 1
        )
        WHERE s.status = 'approved'
        ORDER BY s.last_seen_at IS NULL ASC, s.last_seen_at DESC, s.updated_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $stations = [];
    foreach ($rows as $row) {
        $include = true;
        if ($lat !== null && $lon !== null && $row['latitude'] !== null && $row['longitude'] !== null) {
            $include = vv_distance_km($lat, $lon, (float) $row['latitude'], (float) $row['longitude']) <= $radiusKm;
        }

        if ($include && $terms) {
            $haystack = strtolower((string) $row['location_name'] . ' ' . (string) $row['public_name']);
            $include = false;
            foreach ($terms as $term) {
                if (str_contains($haystack, strtolower($term))) {
                    $include = true;
                    break;
                }
            }
        }

        if ($include) {
            $stations[] = vv_station_public_row($row);
        }
    }

    vv_json([
        'success' => true,
        'source' => 'Værvakt stasjonsnett',
        'count' => count($stations),
        'stations' => $stations,
    ], 200, 'public, max-age=60');
}

function vv_stations_request(PDO $pdo, array $input): void
{
    if (vv_env('STATION_REQUESTS_ENABLED', '0') !== '1') {
        vv_error('Selvbetjent registrering av værstasjoner er ikke åpnet enda.', 403);
    }

    $station = vv_station_create($pdo, $input, 'pending', null);
    vv_json([
        'success' => true,
        'message' => 'Stasjonen er registrert som ventende. Den må godkjennes i Værvakt admin før den kan sende offentlige målinger.',
        'stationId' => $station['publicId'],
    ], 201);
}

function vv_stations_submit(PDO $pdo, array $input): void
{
    $station = vv_station_auth($pdo, $input);
    $reading = vv_station_reading_from_input($input);
    $readingId = vv_station_insert_reading($pdo, $station, $reading, $input);

    vv_json([
        'success' => true,
        'message' => 'Målingen er lagret.',
        'stationId' => (string) $station['public_id'],
        'readingId' => $readingId,
    ], 201);
}

try {
    $pdo = vv_db();
    vv_stations_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vv_stations_latest($pdo);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = vv_request_body();
        $action = strtolower(trim((string) ($_GET['action'] ?? $input['action'] ?? 'submit')));

        if ($action === 'request') {
            vv_stations_request($pdo, $input);
        }

        if ($action === 'submit' || $action === 'reading') {
            vv_stations_submit($pdo, $input);
        }

        vv_error('Ukjent stasjonshandling.', 400);
    }

    vv_error('Metoden er ikke støttet.', 405);
} catch (Throwable $error) {
    error_log('stations failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere værstasjoner akkurat nå.', 500);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bath-location-lib.php';
require_once __DIR__ . '/rate-limit-lib.php';

function vv_bath_reports_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bath_temperature_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(140) NOT NULL,
            yr_location_id VARCHAR(64) NULL,
            reporter VARCHAR(80) NULL,
            temperature DECIMAL(4,1) NOT NULL,
            latitude DECIMAL(10,6) NOT NULL,
            longitude DECIMAL(10,6) NOT NULL,
            heated_water TINYINT(1) NOT NULL DEFAULT 0,
            yr_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            yr_http_status SMALLINT UNSIGNED NULL,
            yr_message VARCHAR(255) NULL,
            yr_request TEXT NOT NULL,
            yr_response TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            yr_sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_bath_created (created_at),
            KEY idx_bath_location (yr_location_id, created_at),
            KEY idx_bath_status (yr_status, created_at),
            KEY idx_bath_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!vv_table_has_column($pdo, 'bath_temperature_reports', 'yr_location_id')) {
        $pdo->exec(
            'ALTER TABLE bath_temperature_reports
             ADD COLUMN yr_location_id VARCHAR(64) NULL AFTER name,
             ADD KEY idx_bath_location (yr_location_id, created_at)'
        );
    }
}

function vv_bath_reports_cleanup(PDO $pdo): void
{
    $retentionDays = max(1, min(90, (int) vv_env('BATH_REPORT_RETENTION_DAYS', '30')));
    $pdo->exec("DELETE FROM bath_temperature_reports WHERE created_at < (NOW() - INTERVAL {$retentionDays} DAY)");
    $pdo->exec('UPDATE bath_temperature_reports SET reporter = NULL WHERE reporter IS NOT NULL');
}

function vv_bath_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'ja', 'on'], true);
}

function vv_bath_response_message(string $body): string
{
    $json = json_decode($body, true);
    if (is_array($json)) {
        foreach (['message', 'error', 'detail', 'title'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                return vv_limit((string) $json[$key], 220);
            }
        }
    }

    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
    return vv_limit($plain !== '' ? $plain : 'Yr svarte uten melding.', 220);
}

function vv_bath_post_to_yr(array $entry): array
{
    if (YR_BATH_API_KEY === '') {
        throw new RuntimeException('YR_BATH_API_KEY mangler på serveren.');
    }

    $payload = json_encode([$entry], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Kunne ikke lage JSON for Yr.');
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . YR_BATH_API_KEY,
        'User-Agent: Vaervakt.no/2.0 (' . vv_env('VAPID_SUBJECT', 'mailto:kontakt@vaervakt.no') . ')',
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init('https://badetemperaturer.yr.no/api/registrere');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Kunne ikke kontakte Yr: ' . ($error ?: 'ukjent feil'));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
            ],
        ]);
        $body = file_get_contents('https://badetemperaturer.yr.no/api/registrere', false, $context);
        if ($body === false) {
            throw new RuntimeException('Kunne ikke kontakte Yr.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int) $match[1];
                break;
            }
        }
    }

    $message = vv_bath_response_message((string) $body);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Yr svarte ' . $status . ': ' . $message);
    }

    return [
        'status' => $status,
        'body' => (string) $body,
        'message' => $message,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cleanup'])) {
    try {
        $pdo = vv_db();
        vv_bath_reports_table($pdo);
        vv_bath_reports_cleanup($pdo);
        vv_json(['success' => true, 'cleaned' => true]);
    } catch (Throwable $error) {
        error_log('bath privacy cleanup failed: ' . $error->getMessage());
        vv_error('Kunne ikke rydde badetemperaturdata akkurat nå.', 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vv_error('Metoden støttes ikke.', 405);
}

$body = vv_request_body();
$locationId = vv_bath_location_id($body['locationId'] ?? $body['yrLocationId'] ?? '');
$temperature = vv_float($body['temperature'] ?? null);
$heatedWater = vv_bath_bool($body['heatedWater'] ?? $body['heated_water'] ?? false);

if ($locationId === '') {
    vv_error('Velg en badeplass fra Yr-listen før du sender.');
}

if ($temperature === null || $temperature < -2 || $temperature > 45) {
    vv_error('Badetemperaturen må være mellom -2 og 45 °C.');
}

try {
    $pdo = vv_db();
    vv_bath_reports_table($pdo);
    vv_public_rate_limits_table($pdo);
    vv_enforce_public_rate_limit(
        $pdo,
        'bath-report',
        (int) vv_env('BATH_RATE_LIMIT', '5'),
        (int) vv_env('BATH_RATE_WINDOW_MINUTES', '30'),
        'Du har sendt mange badetemperaturer på kort tid. Prøv igjen senere.'
    );
} catch (Throwable $error) {
    error_log('bath rate limit failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere badetemperaturen akkurat nå.', 500);
}

try {
    $yrLocation = vv_bath_location_detail($locationId);
} catch (Throwable $error) {
    error_log('bath location validation failed: ' . $error->getMessage());
    vv_error('Yr kunne ikke bekrefte badeplassen akkurat nå. Prøv igjen senere.', 502);
}

if ($yrLocation === null) {
    vv_error('Yr kunne ikke matche den valgte badeplassen. Søk og velg den på nytt.');
}

$name = $yrLocation['name'];
$lat = $yrLocation['lat'];
$lon = $yrLocation['lon'];

$time = new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'));
$entry = [
    'name' => $name,
    'lat' => round($lat, 6),
    'lon' => round($lon, 6),
    'heatedWater' => $heatedWater,
    'temperature' => round($temperature, 1),
    'time' => $time->format(DateTimeInterface::ATOM),
];

$requestJson = json_encode([$entry], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($requestJson === false) {
    vv_error('Kunne ikke formatere badetemperaturen.', 500);
}

try {
    vv_bath_reports_cleanup($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO bath_temperature_reports
        (name, yr_location_id, reporter, temperature, latitude, longitude, heated_water, yr_status, yr_request)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $name,
        $locationId,
        null,
        round($temperature, 1),
        round($lat, 6),
        round($lon, 6),
        $heatedWater ? 1 : 0,
        'pending',
        $requestJson,
    ]);
    $id = (int) $pdo->lastInsertId();

    try {
        $yr = vv_bath_post_to_yr($entry);
        $update = $pdo->prepare(
            'UPDATE bath_temperature_reports
            SET yr_status = ?, yr_http_status = ?, yr_message = ?, yr_response = ?, yr_sent_at = NOW()
            WHERE id = ?'
        );
        $update->execute([
            'sent',
            $yr['status'],
            vv_limit($yr['message'], 255),
            vv_limit($yr['body'], 4000),
            $id,
        ]);

        vv_json([
            'success' => true,
            'message' => 'Badetemperaturen er sendt til Yr. Takk!',
            'status' => 'sent',
            'id' => $id,
            'locationId' => $locationId,
            'locationName' => $name,
        ], 201);
    } catch (Throwable $yrError) {
        $update = $pdo->prepare(
            'UPDATE bath_temperature_reports
            SET yr_status = ?, yr_message = ?, yr_response = ?
            WHERE id = ?'
        );
        $update->execute([
            YR_BATH_API_KEY === '' ? 'pending' : 'failed',
            vv_limit($yrError->getMessage(), 255),
            vv_limit($yrError->getMessage(), 4000),
            $id,
        ]);

        vv_error('Badetemperaturen ble lagret lokalt, men ikke sendt til Yr: ' . $yrError->getMessage(), YR_BATH_API_KEY === '' ? 503 : 502);
    }
} catch (Throwable $error) {
    vv_error('Kunne ikke håndtere badetemperaturen akkurat nå.', 500);
}

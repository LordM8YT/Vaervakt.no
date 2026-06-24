<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function vv_bath_reports_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bath_temperature_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(140) NOT NULL,
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
            KEY idx_bath_status (yr_status, created_at),
            KEY idx_bath_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vv_error('Metoden støttes ikke.', 405);
}

$body = vv_request_body();
$name = vv_limit(trim((string) ($body['name'] ?? $body['locationName'] ?? '')), 140);
$reporter = vv_limit(trim((string) ($body['reporter'] ?? $body['username'] ?? '')), 80);
$temperature = vv_float($body['temperature'] ?? null);
$lat = vv_float($body['lat'] ?? $body['latitude'] ?? null);
$lon = vv_float($body['lon'] ?? $body['longitude'] ?? null);
$heatedWater = vv_bath_bool($body['heatedWater'] ?? $body['heated_water'] ?? false);

if ($name === '') {
    vv_error('Skriv inn navnet på badeplassen.');
}

if ($temperature === null || $temperature < -2 || $temperature > 45) {
    vv_error('Badetemperaturen må være mellom -2 og 45 °C.');
}

if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    vv_error('Badeplassen må ha gyldige koordinater.');
}

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
    $pdo = vv_db();
    vv_bath_reports_table($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO bath_temperature_reports
        (name, reporter, temperature, latitude, longitude, heated_water, yr_status, yr_request)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $name,
        $reporter !== '' ? $reporter : null,
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

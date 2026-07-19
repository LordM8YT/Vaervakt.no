<?php
declare(strict_types=1);

function vv_stations_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_stations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            public_name VARCHAR(120) NOT NULL,
            owner_name VARCHAR(100) NULL,
            owner_contact VARCHAR(160) NULL,
            provider VARCHAR(80) NULL,
            location_name VARCHAR(140) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            coordinate_precision VARCHAR(20) NOT NULL DEFAULT 'area',
            capabilities TEXT NULL,
            api_key_hash VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_station_public_id (public_id),
            KEY idx_station_status (status),
            KEY idx_station_location (location_name),
            KEY idx_station_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS station_readings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            station_id BIGINT UNSIGNED NOT NULL,
            temperature DECIMAL(5,2) NULL,
            humidity DECIMAL(5,2) NULL,
            pressure DECIMAL(7,2) NULL,
            rain_rate DECIMAL(7,2) NULL,
            rain_total DECIMAL(8,2) NULL,
            wind_speed DECIMAL(6,2) NULL,
            wind_direction DECIMAL(6,2) NULL,
            observed_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_payload MEDIUMTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_station_readings_station_time (station_id, observed_at),
            KEY idx_station_readings_received (received_at),
            CONSTRAINT fk_station_readings_station
                FOREIGN KEY (station_id) REFERENCES weather_stations(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_station_public_id(): string
{
    return 'vvst_' . bin2hex(random_bytes(6));
}

function vv_station_api_key(): string
{
    return 'vvs_' . bin2hex(random_bytes(24));
}

function vv_station_key_pepper(): string
{
    $fallback = DB_PASS !== '' ? DB_PASS : DB_NAME . '|vaervakt-stations';
    return vv_env_first(['STATION_KEY_PEPPER', 'VISIT_HASH_SECRET', 'ADMIN_PASSWORD_HASH', 'VAPID_PRIVATE_KEY'], $fallback);
}

function vv_station_key_material(string $publicId, string $apiKey): string
{
    return hash_hmac('sha256', trim($apiKey), vv_station_key_pepper() . '|' . trim($publicId));
}

function vv_station_hash_key(string $publicId, string $apiKey): string
{
    return password_hash(vv_station_key_material($publicId, $apiKey), PASSWORD_DEFAULT);
}

function vv_station_verify_key(string $publicId, string $apiKey, ?string $hash): bool
{
    if ($hash === null || $hash === '' || trim($apiKey) === '') {
        return false;
    }
    return password_verify(vv_station_key_material($publicId, $apiKey), $hash);
}

function vv_station_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() ?: [] as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function vv_station_bearer_token(): string
{
    $authorization = vv_station_header('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        return trim($match[1]);
    }
    return '';
}

function vv_station_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'approved', 'disabled'], true) ? $status : 'pending';
}

function vv_station_precision(string $precision): string
{
    $precision = strtolower(trim($precision));
    return in_array($precision, ['exact', 'area', 'hidden'], true) ? $precision : 'area';
}

function vv_station_capabilities(array $input): array
{
    $raw = $input['capabilities'] ?? null;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    $known = ['temperature', 'humidity', 'pressure', 'rain', 'wind'];
    $capabilities = [];
    foreach ($known as $name) {
        $capabilities[$name] = false;
    }

    foreach ($raw as $key => $value) {
        $name = is_string($key) ? $key : (string) $value;
        $name = strtolower(trim($name));
        if ($name === 'temp') {
            $name = 'temperature';
        }
        if ($name === 'barometer') {
            $name = 'pressure';
        }
        if (array_key_exists($name, $capabilities)) {
            $capabilities[$name] = is_bool($value) ? $value : true;
        }
    }

    if (!$capabilities['temperature']) {
        $capabilities['temperature'] = true;
    }

    return $capabilities;
}

function vv_station_capabilities_json(array $input): string
{
    return json_encode(vv_station_capabilities($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function vv_station_decode_capabilities(?string $json): array
{
    $decoded = json_decode((string) $json, true);
    return vv_station_capabilities(['capabilities' => is_array($decoded) ? $decoded : []]);
}

function vv_station_float_in_range(mixed $value, float $min, float $max): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $float = vv_float($value);
    if ($float === null || $float < $min || $float > $max) {
        return null;
    }
    return $float;
}

function vv_station_first_float(array $input, array $keys, float $min, float $max): ?float
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $input)) {
            return vv_station_float_in_range($input[$key], $min, $max);
        }
    }
    return null;
}

function vv_station_observed_at(array $input): string
{
    $raw = trim((string) ($input['observedAt'] ?? $input['observed_at'] ?? $input['time'] ?? $input['timestamp'] ?? ''));
    if ($raw === '') {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    try {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        vv_error('Tidspunktet for målingen er ikke gyldig.');
    }
}

function vv_station_reading_from_input(array $input): array
{
    $reading = [
        'temperature' => vv_station_first_float($input, ['temperature', 'temp', 'air_temperature'], -60, 60),
        'humidity' => vv_station_first_float($input, ['humidity', 'relative_humidity'], 0, 100),
        'pressure' => vv_station_first_float($input, ['pressure', 'air_pressure_at_sea_level'], 800, 1100),
        'rain_rate' => vv_station_first_float($input, ['rainRate', 'rain_rate', 'rain'], 0, 500),
        'rain_total' => vv_station_first_float($input, ['rainTotal', 'rain_total', 'rain_24h'], 0, 10000),
        'wind_speed' => vv_station_first_float($input, ['windSpeed', 'wind_speed'], 0, 100),
        'wind_direction' => vv_station_first_float($input, ['windDirection', 'wind_direction'], 0, 360),
        'observed_at' => vv_station_observed_at($input),
    ];

    $hasValue = false;
    foreach (['temperature', 'humidity', 'pressure', 'rain_rate', 'rain_total', 'wind_speed', 'wind_direction'] as $key) {
        if ($reading[$key] !== null) {
            $hasValue = true;
            break;
        }
    }
    if (!$hasValue) {
        vv_error('Send minst én måleverdi, for eksempel temperature.');
    }

    return $reading;
}

function vv_station_auth(PDO $pdo, array $input): array
{
    $publicId = trim((string) ($input['stationId'] ?? $input['station_id'] ?? $input['publicId'] ?? vv_station_header('X-Vaervakt-Station-Id')));
    $apiKey = trim((string) ($input['apiKey'] ?? $input['api_key'] ?? ''));
    if ($apiKey === '') {
        $apiKey = trim(vv_station_header('X-Vaervakt-Station-Key'));
    }
    if ($apiKey === '') {
        $apiKey = vv_station_bearer_token();
    }

    if ($publicId === '' || $apiKey === '') {
        vv_error('Mangler stationId eller API-nøkkel.', 401);
    }

    $stmt = $pdo->prepare('SELECT * FROM weather_stations WHERE public_id = ? LIMIT 1');
    $stmt->execute([$publicId]);
    $station = $stmt->fetch();
    if (!$station || !vv_station_verify_key((string) $station['public_id'], $apiKey, $station['api_key_hash'] ?? null)) {
        vv_error('Ugyldig stasjon eller API-nøkkel.', 401);
    }
    if ((string) $station['status'] !== 'approved') {
        vv_error('Stasjonen er ikke godkjent for offentlig innsending enda.', 403);
    }

    return $station;
}

function vv_station_insert_reading(PDO $pdo, array $station, array $reading, array $input): int
{
    $rawPayload = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare('
        INSERT INTO station_readings
            (station_id, temperature, humidity, pressure, rain_rate, rain_total, wind_speed, wind_direction, observed_at, raw_payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        (int) $station['id'],
        $reading['temperature'],
        $reading['humidity'],
        $reading['pressure'],
        $reading['rain_rate'],
        $reading['rain_total'],
        $reading['wind_speed'],
        $reading['wind_direction'],
        $reading['observed_at'],
        $rawPayload,
    ]);

    $pdo->prepare('UPDATE weather_stations SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $station['id']]);
    return (int) $pdo->lastInsertId();
}

function vv_station_public_coordinates(array $station): array
{
    $lat = $station['latitude'] !== null ? (float) $station['latitude'] : null;
    $lon = $station['longitude'] !== null ? (float) $station['longitude'] : null;
    $precision = vv_station_precision((string) ($station['coordinate_precision'] ?? 'area'));

    if ($precision === 'hidden') {
        return ['lat' => null, 'lon' => null, 'precision' => 'hidden'];
    }
    if ($precision === 'area') {
        $lat = $lat !== null ? round($lat, 3) : null;
        $lon = $lon !== null ? round($lon, 3) : null;
    }

    return ['lat' => $lat, 'lon' => $lon, 'precision' => $precision];
}

function vv_station_online(?string $lastSeenAt): bool
{
    if ($lastSeenAt === null || trim($lastSeenAt) === '') {
        return false;
    }

    $timestamp = strtotime($lastSeenAt);
    if ($timestamp === false) {
        return false;
    }

    $maxAgeMinutes = max(5, min(180, (int) vv_env('STATION_ONLINE_MAX_AGE_MINUTES', '20')));
    $ageSeconds = time() - $timestamp;
    return $ageSeconds >= -300 && $ageSeconds <= $maxAgeMinutes * 60;
}

function vv_station_public_timestamp(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Europe/Oslo')))->format(DATE_ATOM);
    } catch (Throwable) {
        return null;
    }
}

function vv_station_public_row(array $row): array
{
    $coords = vv_station_public_coordinates($row);
    $lastSeenAt = $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null;
    return [
        'id' => (string) $row['public_id'],
        'name' => (string) $row['public_name'],
        'provider' => (string) ($row['provider'] ?? 'Værstasjon'),
        'location' => (string) $row['location_name'],
        'verified' => true,
        'sourceType' => 'automatic_station',
        'online' => vv_station_online($lastSeenAt),
        'lat' => $coords['lat'],
        'lon' => $coords['lon'],
        'coordinatePrecision' => $coords['precision'],
        'capabilities' => vv_station_decode_capabilities($row['capabilities'] ?? null),
        'lastSeenAt' => vv_station_public_timestamp($lastSeenAt),
        'reading' => $row['reading_id'] !== null ? [
            'id' => (int) $row['reading_id'],
            'temperature' => $row['temperature'] !== null ? (float) $row['temperature'] : null,
            'humidity' => $row['humidity'] !== null ? (float) $row['humidity'] : null,
            'pressure' => $row['pressure'] !== null ? (float) $row['pressure'] : null,
            'rainRate' => $row['rain_rate'] !== null ? (float) $row['rain_rate'] : null,
            'rainTotal' => $row['rain_total'] !== null ? (float) $row['rain_total'] : null,
            'windSpeed' => $row['wind_speed'] !== null ? (float) $row['wind_speed'] : null,
            'windDirection' => $row['wind_direction'] !== null ? (float) $row['wind_direction'] : null,
            'observedAt' => vv_station_public_timestamp((string) $row['observed_at']),
            'receivedAt' => vv_station_public_timestamp((string) $row['received_at']),
        ] : null,
    ];
}

function vv_station_create(PDO $pdo, array $input, string $status = 'pending', ?string $apiKey = null): array
{
    $name = vv_limit(trim((string) ($input['name'] ?? $input['stationName'] ?? '')), 120);
    $publicName = vv_limit(trim((string) ($input['publicName'] ?? $input['public_name'] ?? $name)), 120);
    $locationName = vv_limit(trim((string) ($input['locationName'] ?? $input['location_name'] ?? $input['location'] ?? '')), 140);
    $provider = vv_limit(trim((string) ($input['provider'] ?? '')), 80);
    $ownerName = vv_limit(trim((string) ($input['ownerName'] ?? $input['owner_name'] ?? '')), 100);
    $ownerContact = vv_limit(trim((string) ($input['ownerContact'] ?? $input['owner_contact'] ?? '')), 160);
    $lat = vv_station_first_float($input, ['lat', 'latitude'], -90, 90);
    $lon = vv_station_first_float($input, ['lon', 'longitude'], -180, 180);
    $precision = vv_station_precision((string) ($input['coordinatePrecision'] ?? $input['coordinate_precision'] ?? 'area'));
    $status = vv_station_status($status);

    if ($name === '' || $publicName === '' || $locationName === '') {
        vv_error('Fyll inn navn, offentlig navn og sted for stasjonen.');
    }
    if (($lat === null) !== ($lon === null)) {
        vv_error('Fyll inn både breddegrad og lengdegrad, eller la begge stå tomme.');
    }

    $publicId = vv_station_public_id();
    $apiKeyHash = null;
    if ($apiKey !== null && $apiKey !== '') {
        $apiKeyHash = vv_station_hash_key($publicId, $apiKey);
    }

    $stmt = $pdo->prepare('
        INSERT INTO weather_stations
            (public_id, name, public_name, owner_name, owner_contact, provider, location_name, latitude, longitude, coordinate_precision, capabilities, api_key_hash, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $publicId,
        $name,
        $publicName,
        $ownerName !== '' ? $ownerName : null,
        $ownerContact !== '' ? $ownerContact : null,
        $provider !== '' ? $provider : null,
        $locationName,
        $lat,
        $lon,
        $precision,
        vv_station_capabilities_json($input),
        $apiKeyHash,
        $status,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'publicId' => $publicId,
        'apiKey' => $apiKey,
    ];
}

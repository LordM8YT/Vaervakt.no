<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_symbol_icon(string $symbol): string
{
    if (str_contains($symbol, 'thunder')) return '⛈️';
    if (str_contains($symbol, 'snow') || str_contains($symbol, 'sleet')) return '❄️';
    if (str_contains($symbol, 'rain')) return '🌧️';
    if (str_contains($symbol, 'fog')) return '🌫️';
    if (str_contains($symbol, 'cloud')) return '☁️';
    if (str_contains($symbol, 'fair')) return '🌤️';
    if (str_contains($symbol, 'clearsky')) return '☀️';
    return '🌤️';
}

function vv_symbol_label(string $symbol): string
{
    $base = str_replace(['_day', '_night', '_polartwilight'], '', $symbol);
    $labels = [
        'clearsky' => 'Klart',
        'fair' => 'Lettskyet',
        'partlycloudy' => 'Delvis skyet',
        'cloudy' => 'Overskyet',
        'rainshowers' => 'Regnbyger',
        'rain' => 'Regn',
        'heavyrain' => 'Kraftig regn',
        'sleet' => 'Sludd',
        'snow' => 'Snø',
        'fog' => 'Tåke',
    ];
    return $labels[$base] ?? ucfirst(str_replace('_', ' ', $base));
}

function vv_feels_like(float $temperature, float $windSpeed, float $humidity): float
{
    $windKmh = $windSpeed * 3.6;
    if ($temperature <= 10 && $windKmh > 4.8) {
        return round(13.12 + 0.6215 * $temperature - 11.37 * ($windKmh ** 0.16) + 0.3965 * $temperature * ($windKmh ** 0.16), 1);
    }
    if ($temperature >= 27 && $humidity >= 40) {
        $f = ($temperature * 9 / 5) + 32;
        $heatIndex = -42.379 + 2.04901523 * $f + 10.14333127 * $humidity - 0.22475541 * $f * $humidity;
        return round(($heatIndex - 32) * 5 / 9, 1);
    }
    return round($temperature, 1);
}

function vv_fetch_met(float $lat, float $lon): array
{
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vv2_met_' . round($lat, 3) . '_' . round($lon, 3) . '.json';
    if (is_readable($cache) && filemtime($cache) !== false && time() - (int) filemtime($cache) < 300) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = 'https://api.met.no/weatherapi/locationforecast/2.0/complete?lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon);
    $data = vv_http_get_json($url, [], 10);
    @file_put_contents($cache, json_encode($data));
    return $data;
}

function vv_fetch_bath(float $lat, float $lon): ?array
{
    if (YR_BATH_API_KEY === '') {
        return null;
    }

    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vv2_bath_' . round($lat, 2) . '_' . round($lon, 2) . '.json';
    $cached = null;
    $cacheAge = null;
    if (is_readable($cache) && filemtime($cache) !== false) {
        $cacheAge = time() - (int) filemtime($cache);
        $cachedPayload = json_decode((string) file_get_contents($cache), true);
        if (is_array($cachedPayload)) {
            $cached = $cachedPayload;
            if ($cacheAge < 900) {
                return $cached;
            }
        }
    }

    $location = rawurlencode($lat . ',' . $lon);
    $url = 'https://badetemperaturer.yr.no/api/locations/' . $location . '/nearestwatertemperatures';
    try {
        $data = vv_http_get_json($url, ['apikey: ' . YR_BATH_API_KEY], 8);
        $items = $data['data'] ?? $data['items'] ?? $data;
        if (is_array($items) && count($items) > 0) {
            @file_put_contents($cache, json_encode($data));
        } elseif ($cached !== null && $cacheAge !== null && $cacheAge < 43200) {
            return $cached;
        }
        return $data;
    } catch (Throwable $error) {
        error_log('bath api failed: ' . $error->getMessage());
        if ($cached !== null && $cacheAge !== null && $cacheAge < 43200) {
            return $cached;
        }
        return null;
    }
}

function vv_normalize_bath_item(array $item, float $lat, float $lon): ?array
{
    $position = is_array($item['position'] ?? null) ? $item['position'] : [];
    $itemLat = vv_float($item['latitude'] ?? $item['lat'] ?? $position['lat'] ?? null);
    $itemLon = vv_float($item['longitude'] ?? $item['lon'] ?? $position['lon'] ?? null);
    $temp = vv_float($item['temperature'] ?? $item['waterTemperature'] ?? null);

    if ($itemLat === null || $itemLon === null || $temp === null) {
        return null;
    }

    return [
        'locationId' => (string) ($item['locationId'] ?? $item['id'] ?? ''),
        'name' => (string) ($item['locationName'] ?? $item['name'] ?? 'Badeplass'),
        'municipality' => (string) ($item['municipality'] ?? ''),
        'county' => (string) ($item['county'] ?? ''),
        'temperature' => $temp,
        'time' => (string) ($item['time'] ?? $item['registeredTime'] ?? ''),
        'lat' => $itemLat,
        'lon' => $itemLon,
        'heatedWater' => (bool) ($item['heatedWater'] ?? false),
        'distanceKm' => round(vv_distance_km($lat, $lon, $itemLat, $itemLon), 1),
        'credit' => 'Badetemperaturer levert av Yr',
    ];
}

function vv_nearby_baths(array $items, float $lat, float $lon, int $limit = 6): array
{
    $nearby = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalized = vv_normalize_bath_item($item, $lat, $lon);
        if ($normalized !== null) {
            $nearby[] = $normalized;
        }
    }

    usort($nearby, static fn (array $a, array $b): int => $a['distanceKm'] <=> $b['distanceKm']);
    return array_slice($nearby, 0, $limit);
}

function vv_nearest_bath(array $items, float $lat, float $lon): ?array
{
    $nearby = vv_nearby_baths($items, $lat, $lon, 1);
    return $nearby[0] ?? null;
}

try {
    $lat = vv_float($_GET['lat'] ?? 58.1504);
    $lon = vv_float($_GET['lon'] ?? 7.9470);
    if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        vv_error('Koordinatene ser ikke gyldige ut.');
    }

    $met = vv_fetch_met($lat, $lon);
    $timeseries = $met['properties']['timeseries'] ?? [];
    if (!is_array($timeseries) || count($timeseries) === 0) {
        throw new RuntimeException('MET response had no timeseries.');
    }

    $now = $timeseries[0];
    $nowDetails = $now['data']['instant']['details'] ?? [];
    $next = $now['data']['next_1_hours'] ?? $now['data']['next_6_hours'] ?? [];
    $symbol = (string) ($next['summary']['symbol_code'] ?? 'partlycloudy_day');
    $temperature = (float) ($nowDetails['air_temperature'] ?? 0);
    $wind = (float) ($nowDetails['wind_speed'] ?? 0);
    $humidity = (float) ($nowDetails['relative_humidity'] ?? 0);
    $rainNextHour = (float) ($next['details']['precipitation_amount'] ?? 0);
    $uv = (float) ($nowDetails['ultraviolet_index_clear_sky'] ?? 0);

    $hourly = [];
    $rain = [];
    $temperatureSeries = [];
    foreach (array_slice($timeseries, 0, 12) as $index => $item) {
        $time = new DateTime((string) $item['time']);
        $details = $item['data']['instant']['details'] ?? [];
        $hourNext = $item['data']['next_1_hours'] ?? $item['data']['next_6_hours'] ?? [];
        $hourSymbol = (string) ($hourNext['summary']['symbol_code'] ?? $symbol);
        $precipitation = (float) ($hourNext['details']['precipitation_amount'] ?? 0);
        $probability = (float) ($hourNext['details']['probability_of_precipitation'] ?? 0);
        $hourTemp = (float) ($details['air_temperature'] ?? $temperature);

        $hourly[] = [
            'hour' => $index === 0 ? 'Nå' : $time->format('H:i'),
            'icon' => vv_symbol_icon($hourSymbol),
            'condition' => vv_symbol_label($hourSymbol),
            'temp' => round($hourTemp),
            'precipitation' => round($precipitation, 1),
            'probability' => round($probability),
            'windSpeed' => round((float) ($details['wind_speed'] ?? 0), 1),
        ];

        if ($index < 6) {
            $rain[] = [
                'hour' => $time->format('H:i'),
                'amount' => round($precipitation, 1),
                'probability' => round($probability),
            ];
        }

        $temperatureSeries[] = [
            'hour' => $time->format('H:i'),
            'value' => round($hourTemp, 1),
        ];
    }

    $days = [];
    foreach ($timeseries as $item) {
        $time = new DateTime((string) $item['time']);
        $key = $time->format('Y-m-d');
        $details = $item['data']['instant']['details'] ?? [];
        $dayNext = $item['data']['next_6_hours'] ?? $item['data']['next_1_hours'] ?? [];
        $temp = (float) ($details['air_temperature'] ?? $temperature);
        if (!isset($days[$key])) {
            $days[$key] = [
                'day' => count($days) === 0 ? 'I dag' : strtoupper($time->format('D')),
                'min' => $temp,
                'max' => $temp,
                'icon' => vv_symbol_icon((string) ($dayNext['summary']['symbol_code'] ?? $symbol)),
            ];
        }
        $days[$key]['min'] = min($days[$key]['min'], $temp);
        $days[$key]['max'] = max($days[$key]['max'], $temp);
        if (count($days) >= 5 && $time->format('H') === '23') {
            break;
        }
    }

    $bathRaw = vv_fetch_bath($lat, $lon);
    $bathItems = is_array($bathRaw) ? ($bathRaw['data'] ?? $bathRaw['items'] ?? $bathRaw) : [];
    $nearbyBaths = is_array($bathItems) ? vv_nearby_baths($bathItems, $lat, $lon) : [];
    $bath = $nearbyBaths[0] ?? null;
    $bathScore = max(0, min(100, (int) round(($temperature * 3.2) - ($wind * 4) - ($rainNextHour * 15) + ($bath['temperature'] ?? 0))));

    vv_json([
        'success' => true,
        'location' => ['lat' => $lat, 'lon' => $lon],
        'current' => [
            'temperature' => round($temperature, 1),
            'feelsLike' => vv_feels_like($temperature, $wind, $humidity),
            'condition' => vv_symbol_label($symbol),
            'icon' => vv_symbol_icon($symbol),
            'windSpeed' => round($wind, 1),
            'humidity' => round($humidity),
            'uvIndex' => round($uv, 1),
        ],
        'summary' => [
            'headline' => vv_symbol_label($symbol),
            'detail' => $rainNextHour > 0 ? 'Nedbør er mulig den neste timen.' : 'Ingen tydelig nedbør akkurat nå.',
        ],
        'insights' => [
            ['label' => 'Vind', 'value' => round($wind, 1) . ' m/s', 'note' => 'MET'],
            ['label' => 'Luft', 'value' => round($humidity) . '%', 'note' => 'Fuktighet'],
            ['label' => 'Regn', 'value' => round($rainNextHour, 1) . ' mm', 'note' => 'Neste time'],
        ],
        'rain' => $rain,
        'temperature' => $temperatureSeries,
        'hourly' => $hourly,
        'forecast' => array_values(array_slice($days, 0, 5)),
        'bathing' => [
            'score' => $bathScore,
            'label' => $bathScore >= 75 ? 'Sterkt badevær' : ($bathScore >= 50 ? 'Mulig badevær' : 'Litt friskt'),
            'waterTemperature' => $bath['temperature'] ?? null,
            'waterTemperatureLocation' => $bath['name'] ?? null,
            'waterTemperatureTime' => $bath['time'] ?? null,
            'waterTemperatureDistanceKm' => $bath['distanceKm'] ?? null,
            'credit' => $bath['credit'] ?? null,
            'nearby' => $nearbyBaths,
            'source' => $bath ? 'Yr badetemperatur + MET-varsel' : 'Beregnet fra MET-varsel. Yr badetemperatur kobles på når nøkkel/data er klar.',
        ],
    ], 200, 'public, max-age=300');
} catch (Throwable $error) {
    error_log('weather failed: ' . $error->getMessage());
    vv_error('Kunne ikke hente værdata akkurat nå.', 500);
}

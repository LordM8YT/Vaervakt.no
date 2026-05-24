<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../config.php';

function vv_number(float $value, int $precision = 1): float
{
    return round($value, $precision);
}

function vv_weather_icon(string $symbol): string
{
    if (strpos($symbol, 'thunder') !== false) return '⛈️';
    if (strpos($symbol, 'snow') !== false || strpos($symbol, 'sleet') !== false) return '❄️';
    if (strpos($symbol, 'rain') !== false) return '🌧️';
    if (strpos($symbol, 'fog') !== false) return '🌫️';
    if (strpos($symbol, 'cloud') !== false) return '☁️';
    if (strpos($symbol, 'fair') !== false || strpos($symbol, 'clearsky') !== false) return '☀️';
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

function vv_day_label(DateTime $date, int $index): string
{
    if ($index === 0) return 'I DAG';
    $labels = [
        'Mon' => 'MAN',
        'Tue' => 'TIR',
        'Wed' => 'ONS',
        'Thu' => 'TOR',
        'Fri' => 'FRE',
        'Sat' => 'LØR',
        'Sun' => 'SØN',
    ];
    return $labels[$date->format('D')] ?? strtoupper($date->format('D'));
}

function vv_fetch_met(float $lat, float $lon): array
{
    $cacheKey = sprintf('vaervakt_met_%s_%s.json', str_replace('.', '_', (string) round($lat, 3)), str_replace('.', '_', (string) round($lon, 3)));
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey;
    if (is_readable($cachePath) && filemtime($cachePath) !== false && time() - (int) filemtime($cachePath) < 300) {
        $cached = file_get_contents($cachePath);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) return $decoded;
        }
    }

    $url = 'https://api.met.no/weatherapi/locationforecast/2.0/complete?lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon);
    $userAgent = 'Vaervakt/2026 ' . VAPID_SUBJECT;
    $raw = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);
        $raw = curl_exec($curl);
        curl_close($curl);
    }

    if ($raw === false) {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: " . $userAgent . "\r\nAccept: application/json\r\n",
                'timeout' => 8,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
    }
    if ($raw === false) {
        throw new RuntimeException('MET API svarte ikke.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['properties']['timeseries']) || !is_array($decoded['properties']['timeseries'])) {
        throw new RuntimeException('MET API ga uventet respons.');
    }

    file_put_contents($cachePath, $raw, LOCK_EX);
    return $decoded;
}

function vv_details(array $point): array
{
    return $point['data']['instant']['details'] ?? [];
}

function vv_next_details(array $point, string $period): array
{
    return $point['data'][$period]['details'] ?? [];
}

function vv_symbol(array $point): string
{
    return (string) ($point['data']['next_1_hours']['summary']['symbol_code']
        ?? $point['data']['next_6_hours']['summary']['symbol_code']
        ?? 'fair_day');
}

try {
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : 58.1504;
    $lon = isset($_GET['lon']) ? (float) $_GET['lon'] : 7.9470;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new InvalidArgumentException('Ugyldige koordinater.');
    }

    $met = vv_fetch_met($lat, $lon);
    $timeseries = $met['properties']['timeseries'];
    $now = $timeseries[0];
    $nowDetails = vv_details($now);
    $symbol = vv_symbol($now);

    $rain = [];
    foreach (array_slice($timeseries, 0, 6) as $point) {
        $next = vv_next_details($point, 'next_1_hours');
        $rain[] = [
            'hour' => (new DateTime((string) $point['time']))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'amount' => vv_number((float) ($next['precipitation_amount'] ?? 0), 1),
            'probability' => vv_number((float) ($next['probability_of_precipitation'] ?? 0), 0),
        ];
    }

    $temperature = [];
    foreach (array_slice($timeseries, 0, 8) as $point) {
        $details = vv_details($point);
        $temperature[] = [
            'hour' => (new DateTime((string) $point['time']))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'value' => vv_number((float) ($details['air_temperature'] ?? 0), 1),
        ];
    }

    $dailyBuckets = [];
    foreach ($timeseries as $point) {
        $date = (new DateTime((string) $point['time']))->setTimezone(new DateTimeZone('Europe/Oslo'));
        $key = $date->format('Y-m-d');
        $details = vv_details($point);
        $temp = (float) ($details['air_temperature'] ?? 0);
        if (!isset($dailyBuckets[$key])) {
            $dailyBuckets[$key] = [
                'date' => clone $date,
                'temp' => $temp,
                'symbol' => vv_symbol($point),
            ];
            continue;
        }

        if ($temp > $dailyBuckets[$key]['temp']) {
            $dailyBuckets[$key]['temp'] = $temp;
        }

        $hour = (int) $date->format('H');
        if ($hour >= 10 && $hour <= 14) {
            $dailyBuckets[$key]['symbol'] = vv_symbol($point);
        }
    }

    $daily = [];
    foreach (array_slice($dailyBuckets, 0, 5) as $bucket) {
        $daily[] = [
            'day' => vv_day_label($bucket['date'], count($daily)),
            'icon' => vv_weather_icon((string) $bucket['symbol']),
            'temp' => round((float) $bucket['temp']),
        ];
    }

    echo json_encode([
        'success' => true,
        'source' => 'MET Norway Locationforecast 2.0 complete',
        'location' => [
            'id' => 'kristiansand-no',
            'name' => 'Kristiansand, NO',
            'lat' => $lat,
            'lon' => $lon,
        ],
        'current' => [
            'temperature' => vv_number((float) ($nowDetails['air_temperature'] ?? 0), 1),
            'feelsLike' => vv_number((float) ($nowDetails['air_temperature'] ?? 0), 1),
            'uvIndex' => vv_number((float) ($nowDetails['ultraviolet_index_clear_sky'] ?? 0), 1),
            'condition' => vv_symbol_label($symbol),
            'icon' => vv_weather_icon($symbol),
            'windSpeed' => vv_number((float) ($nowDetails['wind_speed'] ?? 0), 1),
            'humidity' => vv_number((float) ($nowDetails['relative_humidity'] ?? 0), 0),
        ],
        'rain' => $rain,
        'temperature' => $temperature,
        'forecast' => $daily,
        'updatedAt' => $now['time'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 502);
    error_log('MET weather api failed: ' . $error->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Kunne ikke hente værdata fra MET akkurat nå.',
    ], JSON_UNESCAPED_UNICODE);
}

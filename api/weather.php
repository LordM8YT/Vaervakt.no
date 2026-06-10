<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../config.php';

function vv_number(float $value, int $precision = 1): float
{
    return round($value, $precision);
}

function vv_met_user_agent(): string
{
    $contact = trim((string) VAPID_SUBJECT);
    if ($contact === '' || stripos($contact, 'example.com') !== false || stripos($contact, 'your-email') !== false) {
        $contact = 'mailto:patrick@vaarvakt.no';
    }

    return 'Vaervakt.no/2026 (' . $contact . ')';
}

function vv_feels_like(float $temperature, float $windSpeed, float $humidity): float
{
    $windKmh = $windSpeed * 3.6;
    if ($temperature <= 10 && $windKmh > 4.8) {
        return vv_number(13.12 + 0.6215 * $temperature - 11.37 * ($windKmh ** 0.16) + 0.3965 * $temperature * ($windKmh ** 0.16), 1);
    }

    if ($temperature >= 27 && $humidity >= 40) {
        $fahrenheit = ($temperature * 9 / 5) + 32;
        $heatIndexF = -42.379 + 2.04901523 * $fahrenheit + 10.14333127 * $humidity
            - 0.22475541 * $fahrenheit * $humidity - 0.00683783 * ($fahrenheit ** 2)
            - 0.05481717 * ($humidity ** 2) + 0.00122874 * ($fahrenheit ** 2) * $humidity
            + 0.00085282 * $fahrenheit * ($humidity ** 2) - 0.00000199 * ($fahrenheit ** 2) * ($humidity ** 2);
        return vv_number(($heatIndexF - 32) * 5 / 9, 1);
    }

    return vv_number($temperature, 1);
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
            if (is_array($decoded) && isset($decoded['properties']['timeseries']) && is_array($decoded['properties']['timeseries'])) {
                return $decoded;
            }
        }
    }

    $url = 'https://api.met.no/weatherapi/locationforecast/2.0/complete?lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon);
    $userAgent = vv_met_user_agent();
    $raw = false;
    $statusCode = 0;

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
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw !== false && $statusCode >= 400) {
            throw new RuntimeException('MET API svarte med HTTP ' . $statusCode . '.');
        }
    }

    if ($raw === false) {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: " . $userAgent . "\r\nAccept: application/json\r\n",
                'timeout' => 8,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $statusCode = (int) $matches[1];
                    break;
                }
            }
        }
        if ($raw !== false && $statusCode >= 400) {
            throw new RuntimeException('MET API svarte med HTTP ' . $statusCode . '.');
        }
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

function vv_precipitation_amount(array $point, string $period = 'next_1_hours'): float
{
    $details = vv_next_details($point, $period);
    return vv_number((float) ($details['precipitation_amount'] ?? 0), 1);
}

function vv_precipitation_probability(array $point, string $period = 'next_1_hours'): float
{
    $details = vv_next_details($point, $period);
    return vv_number((float) ($details['probability_of_precipitation'] ?? 0), 0);
}

function vv_build_hourly(array $timeseries): array
{
    $hourly = [];
    foreach (array_slice($timeseries, 0, 12) as $point) {
        $details = vv_details($point);
        $symbol = vv_symbol($point);
        $hourly[] = [
            'hour' => (new DateTime((string) $point['time']))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'icon' => vv_weather_icon($symbol),
            'condition' => vv_symbol_label($symbol),
            'temp' => vv_number((float) ($details['air_temperature'] ?? 0), 1),
            'precipitation' => vv_precipitation_amount($point),
            'probability' => vv_precipitation_probability($point),
            'windSpeed' => vv_number((float) ($details['wind_speed'] ?? 0), 1),
        ];
    }

    return $hourly;
}

function vv_wind_status(float $windSpeed): string
{
    if ($windSpeed >= 13.9) return 'Kraftig vind';
    if ($windSpeed >= 8) return 'Friskt';
    if ($windSpeed >= 4) return 'Lett bris';
    return 'Rolig';
}

function vv_build_summary(string $condition, float $temperature, float $windSpeed, float $rainAmount, float $rainProbability): array
{
    if ($rainAmount >= 4 || $rainProbability >= 75) {
        return [
            'headline' => 'Ta med regntøy',
            'detail' => 'Det er tydelig nedbørsignal de neste timene, så Værvakt holder ekstra øye med lokale rapporter.',
        ];
    }

    if ($windSpeed >= 10) {
        return [
            'headline' => 'Vindfullt værbilde',
            'detail' => 'Vinden er den viktigste faktoren akkurat nå, spesielt nær kyst og åpne områder.',
        ];
    }

    if ($temperature >= 22 && $rainAmount < 1) {
        return [
            'headline' => 'Sommerfølelse',
            'detail' => 'Varmt nok til uteplaner. Sjekk Badevær-kortet før du pakker håndkle.',
        ];
    }

    return [
        'headline' => $condition ?: 'Rolig værbilde',
        'detail' => 'Varslet ser ganske stabilt ut. Lokale rapporter gir fasiten på bakken.',
    ];
}

function vv_build_bathing(array $timeseries, array $nowDetails): array
{
    $temperature = (float) ($nowDetails['air_temperature'] ?? 0);
    $windSpeed = (float) ($nowDetails['wind_speed'] ?? 0);
    $uvIndex = (float) ($nowDetails['ultraviolet_index_clear_sky'] ?? 0);
    $rainAmount = 0.0;
    $rainProbability = 0.0;

    foreach (array_slice($timeseries, 0, 6) as $point) {
        $rainAmount += vv_precipitation_amount($point);
        $rainProbability = max($rainProbability, vv_precipitation_probability($point));
    }

    if ($rainAmount >= 2 || $rainProbability >= 70) {
        $score = 42;
        $label = 'Vent litt';
        $emoji = '🌧️';
        $description = 'Det ligger an til regn i nærheten. Bad kan funke, men håndkleet taper.';
    } elseif ($temperature >= 23 && $windSpeed <= 6) {
        $score = 92;
        $label = 'Badeklar';
        $emoji = '🏖️';
        $description = 'Varm luft, lite vind og lav regnfare. Dette er typisk “pakk håndkle”-vær.';
    } elseif ($temperature >= 19 && $windSpeed <= 8) {
        $score = 74;
        $label = 'Friskt og fint';
        $emoji = '🌊';
        $description = 'Helt brukbart badevær, spesielt hvis sola titter fram eller vannet er lunt.';
    } elseif ($temperature >= 16) {
        $score = 56;
        $label = 'Litt friskt';
        $emoji = '🩳';
        $description = 'Mulig for de tøffe. Sjekk lokale badetemp-rapporter før du hopper uti.';
    } else {
        $score = 34;
        $label = 'Kaldt på land';
        $emoji = '🧊';
        $description = 'Ikke akkurat sydenstemning. Badevær-kortet kan fortsatt være nyttig for de uredde.';
    }

    return [
        'score' => $score,
        'label' => $label,
        'emoji' => $emoji,
        'description' => $description,
        'airTemperature' => vv_number($temperature, 1),
        'windSpeed' => vv_number($windSpeed, 1),
        'rainAmount' => vv_number($rainAmount, 1),
        'rainProbability' => vv_number($rainProbability, 0),
        'uvIndex' => vv_number($uvIndex, 1),
        'waterTemperature' => null,
        'source' => 'Beregnet fra MET-varsel. Badetemp kan kobles på når Yr-nøkkel eller lokale målinger er klare.',
    ];
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
    $currentTemperature = (float) ($nowDetails['air_temperature'] ?? 0);
    $currentWindSpeed = (float) ($nowDetails['wind_speed'] ?? 0);
    $currentHumidity = (float) ($nowDetails['relative_humidity'] ?? 0);

    $rain = [];
    foreach (array_slice($timeseries, 0, 6) as $point) {
        $rain[] = [
            'hour' => (new DateTime((string) $point['time']))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'amount' => vv_precipitation_amount($point),
            'probability' => vv_precipitation_probability($point),
        ];
    }

    $rainAmountNext6h = array_reduce($rain, static fn(float $carry, array $item): float => $carry + (float) ($item['amount'] ?? 0), 0.0);
    $rainProbabilityNext6h = array_reduce($rain, static fn(float $carry, array $item): float => max($carry, (float) ($item['probability'] ?? 0)), 0.0);
    $summary = vv_build_summary(vv_symbol_label($symbol), $currentTemperature, $currentWindSpeed, $rainAmountNext6h, $rainProbabilityNext6h);
    $bathing = vv_build_bathing($timeseries, $nowDetails);
    $hourly = vv_build_hourly($timeseries);

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
            'id' => (abs($lat - 58.1504) < 0.0001 && abs($lon - 7.9470) < 0.0001) ? 'kristiansand-no' : 'user-location',
            'name' => (abs($lat - 58.1504) < 0.0001 && abs($lon - 7.9470) < 0.0001) ? 'Kristiansand, NO' : 'Din posisjon',
            'lat' => $lat,
            'lon' => $lon,
        ],
        'current' => [
            'temperature' => vv_number($currentTemperature, 1),
            'feelsLike' => vv_feels_like($currentTemperature, $currentWindSpeed, $currentHumidity),
            'uvIndex' => vv_number((float) ($nowDetails['ultraviolet_index_clear_sky'] ?? 0), 1),
            'condition' => vv_symbol_label($symbol),
            'icon' => vv_weather_icon($symbol),
            'windSpeed' => vv_number($currentWindSpeed, 1),
            'humidity' => vv_number($currentHumidity, 0),
        ],
        'summary' => $summary,
        'insights' => [
            [
                'label' => 'Vind',
                'value' => vv_number($currentWindSpeed, 1) . ' m/s',
                'note' => vv_wind_status($currentWindSpeed),
            ],
            [
                'label' => 'Luft',
                'value' => vv_number($currentHumidity, 0) . '%',
                'note' => 'Fuktighet',
            ],
            [
                'label' => 'Regn',
                'value' => $rainAmountNext6h > 0 ? vv_number($rainAmountNext6h, 1) . ' mm' : 'Tørt',
                'note' => 'Neste 6 timer',
            ],
        ],
        'rain' => $rain,
        'temperature' => $temperature,
        'hourly' => $hourly,
        'bathing' => $bathing,
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

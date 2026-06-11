<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');

require_once __DIR__ . '/../config.php';

function vv_bathing_search_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function vv_bathing_user_agent(): string
{
    $contact = trim((string) VAPID_SUBJECT);
    if ($contact === '' || stripos($contact, 'example.com') !== false || stripos($contact, 'your-email') !== false) {
        $contact = 'mailto:patrick@vaarvakt.no';
    }

    return 'Vaervakt.no/2026 (' . $contact . ')';
}

function vv_bathing_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function vv_bathing_substr(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function vv_bathing_normalize(string $value): string
{
    $value = vv_bathing_lower($value);
    $value = strtr($value, ['æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e']);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function vv_bathing_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function vv_bathing_http_json(string $url, array $headers, string $cacheKey, int $ttl): ?array
{
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey;
    if (is_readable($cachePath) && filemtime($cachePath) !== false && time() - (int) filemtime($cachePath) < $ttl) {
        $cached = file_get_contents($cachePath);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $raw = false;
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    }

    if ($raw === false) {
        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", $headers) . "\r\n",
                'ignore_errors' => true,
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
    }

    if ($raw === false || $statusCode >= 400) {
        error_log('Bathing search request failed with HTTP ' . $statusCode . ' for ' . $url);
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    file_put_contents($cachePath, $raw, LOCK_EX);
    return $decoded;
}

function vv_bathing_fetch_all(): ?array
{
    if (!defined('YR_BATH_API_KEY') || trim((string) YR_BATH_API_KEY) === '') {
        return null;
    }

    return vv_bathing_http_json(
        'https://badetemperaturer.yr.no/api/watertemperatures',
        [
            'Accept: application/json',
            'User-Agent: ' . vv_bathing_user_agent(),
            'apikey: ' . YR_BATH_API_KEY,
        ],
        'vaervakt_yr_bath_all.json',
        1800
    );
}

function vv_bathing_fetch_nearest(float $lat, float $lon): ?array
{
    if (!defined('YR_BATH_API_KEY') || trim((string) YR_BATH_API_KEY) === '') {
        return null;
    }

    $location = rawurlencode((string) round($lat, 6) . ',' . (string) round($lon, 6));
    return vv_bathing_http_json(
        'https://badetemperaturer.yr.no/api/locations/' . $location . '/nearestwatertemperatures',
        [
            'Accept: application/json',
            'User-Agent: ' . vv_bathing_user_agent(),
            'apikey: ' . YR_BATH_API_KEY,
        ],
        'vaervakt_yr_bath_nearest_' . str_replace('.', '_', (string) round($lat, 2)) . '_' . str_replace('.', '_', (string) round($lon, 2)) . '.json',
        1800
    );
}

function vv_bathing_geocode(string $query): ?array
{
    $queries = [$query];
    if (stripos($query, 'norge') === false && stripos($query, 'norway') === false) {
        $queries[] = $query . ' Norge';
    }

    foreach ($queries as $search) {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $search,
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'limit' => '1',
            'countrycodes' => 'no',
            'accept-language' => 'nb-NO,nb,no,en',
        ]);

        $result = vv_bathing_http_json(
            $url,
            [
                'Accept: application/json',
                'User-Agent: ' . vv_bathing_user_agent(),
            ],
            'vaervakt_bathing_geocode_' . sha1(vv_bathing_normalize($search)) . '.json',
            86400
        );

        if (is_array($result) && isset($result[0]['lat'], $result[0]['lon'])) {
            return [
                'name' => trim((string) ($result[0]['name'] ?? $query)),
                'lat' => (float) $result[0]['lat'],
                'lon' => (float) $result[0]['lon'],
            ];
        }
    }

    return null;
}

function vv_bathing_format_result(array $item, ?float $originLat = null, ?float $originLon = null): ?array
{
    $position = is_array($item['position'] ?? null) ? $item['position'] : [];
    $lat = isset($position['lat']) ? (float) $position['lat'] : null;
    $lon = isset($position['lon']) ? (float) $position['lon'] : null;
    $name = trim((string) ($item['locationName'] ?? ''));

    if ($name === '' || $lat === null || $lon === null || !isset($item['temperature'])) {
        return null;
    }

    $distanceKm = null;
    if ($originLat !== null && $originLon !== null) {
        $distanceKm = vv_bathing_distance_km($originLat, $originLon, $lat, $lon);
    } elseif (isset($item['distanceKm'])) {
        $distanceKm = (float) $item['distanceKm'];
    }

    return [
        'id' => (string) ($item['locationId'] ?? ('bath-' . substr(sha1($name . $lat . $lon), 0, 12))),
        'name' => $name,
        'municipality' => trim((string) ($item['municipality'] ?? '')),
        'county' => trim((string) ($item['county'] ?? '')),
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
        'waterTemperature' => round((float) $item['temperature'], 1),
        'time' => $item['time'] ?? null,
        'distanceKm' => $distanceKm !== null ? round($distanceKm, 1) : null,
        'heatedWater' => (bool) ($item['heatedWater'] ?? false),
        'credit' => 'Badetemperaturer levert av Yr',
    ];
}

function vv_bathing_search_all(array $items, string $query): array
{
    $normalizedQuery = vv_bathing_normalize($query);
    $terms = array_values(array_filter(explode(' ', $normalizedQuery), static fn(string $term): bool => strlen($term) >= 2));
    if ($terms === []) {
        return [];
    }

    $matches = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = (string) ($item['locationName'] ?? '');
        $municipality = (string) ($item['municipality'] ?? '');
        $county = (string) ($item['county'] ?? '');
        $haystack = vv_bathing_normalize($name . ' ' . $municipality . ' ' . $county);

        foreach ($terms as $term) {
            if (strpos($haystack, $term) === false) {
                continue 2;
            }
        }

        $normalizedName = vv_bathing_normalize($name);
        $score = 50;
        if ($normalizedName === $normalizedQuery) {
            $score = 120;
        } elseif (str_starts_with($normalizedName, $normalizedQuery)) {
            $score = 95;
        } elseif (strpos($normalizedName, $normalizedQuery) !== false) {
            $score = 80;
        }

        $formatted = vv_bathing_format_result($item);
        if ($formatted !== null) {
            $matches[] = ['score' => $score, 'result' => $formatted];
        }
    }

    usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice(array_map(static fn(array $item): array => $item['result'], $matches), 0, 8);
}

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    $query = vv_bathing_substr($query, 80);
    if ($query === '' || strlen($query) < 2) {
        throw new InvalidArgumentException('Skriv minst to tegn for å søke etter badeplass.');
    }

    if (!defined('YR_BATH_API_KEY') || trim((string) YR_BATH_API_KEY) === '') {
        vv_bathing_search_error('Badetemperatur-søk mangler API-nøkkel på serveren.', 503);
    }

    $results = [];
    $mode = 'yr-search';
    $all = vv_bathing_fetch_all();
    if (is_array($all)) {
        $results = vv_bathing_search_all($all, $query);
    }

    if ($results === []) {
        $origin = vv_bathing_geocode($query);
        if ($origin !== null) {
            $mode = 'nearby';
            $nearest = vv_bathing_fetch_nearest((float) $origin['lat'], (float) $origin['lon']);
            if (is_array($nearest)) {
                foreach ($nearest as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $formatted = vv_bathing_format_result($item, (float) $origin['lat'], (float) $origin['lon']);
                    if ($formatted !== null) {
                        $results[] = $formatted;
                    }
                }
                usort($results, static fn(array $a, array $b): int => ((float) ($a['distanceKm'] ?? 999)) <=> ((float) ($b['distanceKm'] ?? 999)));
                $results = array_slice($results, 0, 6);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'query' => $query,
        'mode' => $mode,
        'results' => $results,
        'credit' => $results !== [] ? 'Badetemperaturer levert av Yr' : null,
        'message' => $results === [] ? 'Fant ingen ferske badetemperaturer nær søket.' : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 502);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Kunne ikke søke etter badetemperatur akkurat nå.',
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
}

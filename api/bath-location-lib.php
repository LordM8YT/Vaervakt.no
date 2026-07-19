<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_bath_location_id(mixed $value): string
{
    $locationId = trim((string) $value);
    return preg_match('/^[A-Za-z0-9-]{1,64}$/', $locationId) === 1
        ? $locationId
        : '';
}

function vv_bath_location_cache(string $key, int $maxAgeSeconds, callable $loader): array
{
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vv2_bath_location_' . hash('sha256', $key) . '.json';
    if (
        is_readable($cache) &&
        filemtime($cache) !== false &&
        time() - (int) filemtime($cache) < $maxAgeSeconds
    ) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $data = $loader();
    @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $data;
}

function vv_bath_normalize_location(array $item): ?array
{
    $locationId = vv_bath_location_id($item['locationId'] ?? $item['id'] ?? '');
    $name = vv_limit(trim((string) ($item['name'] ?? '')), 140);
    $category = trim((string) ($item['categoryName'] ?? $item['category']['name'] ?? ''));
    $position = is_array($item['position'] ?? null) ? $item['position'] : [];
    $lat = vv_float($item['lat'] ?? $item['latitude'] ?? $position['lat'] ?? null);
    $lon = vv_float($item['lon'] ?? $item['longitude'] ?? $position['lon'] ?? null);
    $regionName = vv_limit(trim((string) (
        $item['regionName'] ??
        $item['urlPath'] ??
        $item['region']['name'] ??
        ''
    )), 160);

    if (
        $locationId === '' ||
        $name === '' ||
        strcasecmp($category, 'Badeplass') !== 0 ||
        $lat === null ||
        $lon === null ||
        $lat < -90 ||
        $lat > 90 ||
        $lon < -180 ||
        $lon > 180
    ) {
        return null;
    }

    return [
        'locationId' => $locationId,
        'name' => $name,
        'regionName' => $regionName,
        'categoryName' => 'Badeplass',
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
    ];
}

function vv_bath_search_locations(string $query, int $limit = 8): array
{
    $query = vv_limit(trim($query), 80);
    if (vv_len($query) < 2) {
        return [];
    }

    $data = vv_bath_location_cache(
        'search:' . (function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query)),
        600,
        static fn (): array => vv_http_get_json(
            'https://badetemperaturer.yr.no/api/v0/locations/searchbathingspots?q=' .
            rawurlencode($query),
            [],
            8
        )
    );

    $locations = [];
    $seen = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $location = vv_bath_normalize_location($item);
        if ($location === null || isset($seen[$location['locationId']])) {
            continue;
        }
        $seen[$location['locationId']] = true;
        $locations[] = $location;
        if (count($locations) >= max(1, min(12, $limit))) {
            break;
        }
    }

    return $locations;
}

function vv_bath_location_detail(string $locationId): ?array
{
    $locationId = vv_bath_location_id($locationId);
    if ($locationId === '') {
        return null;
    }

    $data = vv_bath_location_cache(
        'detail:' . $locationId,
        86400,
        static fn (): array => vv_http_get_json(
            'https://badetemperaturer.yr.no/api/v0/locations/' . rawurlencode($locationId),
            [],
            8
        )
    );

    return vv_bath_normalize_location($data);
}

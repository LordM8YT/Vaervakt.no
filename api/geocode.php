<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_geocode_place_name(array $item): string
{
    $names = is_array($item['stedsnavn'] ?? null) ? $item['stedsnavn'] : [];
    foreach ($names as $name) {
        if (!is_array($name)) {
            continue;
        }
        $spelling = trim((string) ($name['skrivemåte'] ?? ''));
        if ($spelling !== '' && ($name['skrivemåtestatus'] ?? '') === 'godkjent og prioritert') {
            return $spelling;
        }
    }

    foreach ($names as $name) {
        if (is_array($name) && trim((string) ($name['skrivemåte'] ?? '')) !== '') {
            return trim((string) $name['skrivemåte']);
        }
    }

    return '';
}

function vv_geocode_kartverket_place(float $lat, float $lon): ?array
{
    $url = 'https://api.kartverket.no/stedsnavn/v1/punkt?'
        . http_build_query([
            'nord' => $lat,
            'ost' => $lon,
            'koordsys' => 4258,
            'radius' => 1500,
            'utkoordsys' => 4258,
            'treffPerSide' => 500,
            'side' => 1,
        ]);
    $payload = vv_http_get_json($url, [], 8);
    $items = is_array($payload['navn'] ?? null) ? $payload['navn'] : [];
    $rules = [
        'Boligfelt' => ['maxDistance' => 1400, 'penalty' => 0],
        'Tettbebyggelse' => ['maxDistance' => 1400, 'penalty' => 50],
        'Tettsteddel' => ['maxDistance' => 1600, 'penalty' => 80],
        'Grend' => ['maxDistance' => 1600, 'penalty' => 100],
        'Bygdelag (bygd)' => ['maxDistance' => 2000, 'penalty' => 120],
        'Hyttefelt' => ['maxDistance' => 1400, 'penalty' => 120],
        'Industriområde' => ['maxDistance' => 1200, 'penalty' => 140],
        'Bydel' => ['maxDistance' => 3000, 'penalty' => 180],
        'Administrativ bydel' => ['maxDistance' => 5000, 'penalty' => 240],
        'Gard' => ['maxDistance' => 900, 'penalty' => 260],
        'Navnegard' => ['maxDistance' => 1200, 'penalty' => 280],
        'Tettsted' => ['maxDistance' => 4000, 'penalty' => 600],
        'By' => ['maxDistance' => 8000, 'penalty' => 1000],
    ];
    $best = null;
    $bestScore = INF;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = trim((string) ($item['navneobjekttype'] ?? ''));
        $rule = $rules[$type] ?? null;
        $distance = vv_float($item['meterFraPunkt'] ?? null);
        $name = vv_geocode_place_name($item);
        if ($rule === null || $distance === null || $distance > $rule['maxDistance'] || $name === '') {
            continue;
        }

        $score = $distance + $rule['penalty'];
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = [
                'name' => $name,
                'type' => $type,
                'distanceMeters' => round($distance),
            ];
        }
    }

    return $best;
}

function vv_geocode_kartverket_municipality(float $lat, float $lon): string
{
    $url = 'https://ws.geonorge.no/adresser/v1/punktsok?'
        . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'radius' => 3000,
            'treffPerSide' => 1,
            'side' => 0,
        ]);
    $payload = vv_http_get_json($url, [], 8);
    $addresses = is_array($payload['adresser'] ?? null) ? $payload['adresser'] : [];
    $municipality = trim((string) ($addresses[0]['kommunenavn'] ?? ''));
    if ($municipality === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($municipality, MB_CASE_TITLE, 'UTF-8')
        : ucfirst(strtolower($municipality));
}

function vv_geocode_nominatim_place(float $lat, float $lon): array
{
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon) . '&zoom=14&addressdetails=1&accept-language=nb';
    $data = vv_http_get_json($url, [], 8);
    $address = is_array($data['address'] ?? null) ? $data['address'] : [];
    $name = $address['quarter'] ?? $address['suburb'] ?? $address['city_district'] ?? $address['neighbourhood'] ?? $address['village'] ?? $address['town'] ?? $address['city'] ?? $data['name'] ?? 'Din posisjon';
    $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? '';

    return [
        'name' => trim((string) $name),
        'municipality' => trim((string) $city),
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? vv_request_body() : $_GET;
    $lat = vv_float($input['lat'] ?? null);
    $lon = vv_float($input['lon'] ?? null);

    if ($lat !== null && $lon !== null) {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            vv_error('Koordinatene ser ikke gyldige ut.');
        }
        $provider = 'kartverket';
        $place = null;
        $municipality = '';

        try {
            $place = vv_geocode_kartverket_place($lat, $lon);
            if ($place !== null) {
                $municipality = vv_geocode_kartverket_municipality($lat, $lon);
            }
        } catch (Throwable $kartverketError) {
            error_log('kartverket reverse geocode failed: ' . $kartverketError->getMessage());
        }

        if ($place === null) {
            $provider = 'nominatim';
            $fallback = vv_geocode_nominatim_place($lat, $lon);
            $place = ['name' => $fallback['name'], 'type' => null, 'distanceMeters' => null];
            $municipality = $fallback['municipality'];
        }

        $name = trim((string) ($place['name'] ?? 'Din posisjon'));
        $city = trim($municipality);
        $label = trim((string) $name . ($city !== '' && $city !== $name ? ', ' . $city : ''));

        vv_json([
            'success' => true,
            'result' => [
                'name' => $label !== '' ? $label : 'Din posisjon',
                'source' => 'user',
                'provider' => $provider,
                'placeType' => $place['type'] ?? null,
                'distanceMeters' => $place['distanceMeters'] ?? null,
            ],
        ], 200, 'no-store');
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    if (vv_len($query) < 2) {
        vv_error('Skriv minst to tegn for å søke.');
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&addressdetails=1&q=' . rawurlencode($query);
    $items = vv_http_get_json($url, [], 8);
    $results = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemLat = vv_float($item['lat'] ?? null);
        $itemLon = vv_float($item['lon'] ?? null);
        if ($itemLat === null || $itemLon === null) {
            continue;
        }
        $results[] = [
            'id' => 'search-' . substr(hash('sha1', (string) ($item['place_id'] ?? $item['display_name'] ?? $query)), 0, 12),
            'name' => (string) ($item['display_name'] ?? $query),
            'lat' => $itemLat,
            'lon' => $itemLon,
            'source' => 'search',
            'searchQuery' => $query,
        ];
    }

    vv_json(['success' => true, 'results' => $results], 200, 'public, max-age=900');
} catch (Throwable $error) {
    error_log('geocode failed: ' . $error->getMessage());
    vv_error('Kunne ikke søke etter sted akkurat nå.', 500);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);

    if ($lat !== null && $lon !== null) {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            vv_error('Koordinatene ser ikke gyldige ut.');
        }
        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon) . '&zoom=14&addressdetails=1&accept-language=nb';
        $data = vv_http_get_json($url, [], 8);
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $name = $address['suburb']
            ?? $address['city_district']
            ?? $address['borough']
            ?? $address['village']
            ?? $address['town']
            ?? $address['city']
            ?? $address['municipality']
            ?? $address['county']
            ?? 'Din posisjon';
        $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['county'] ?? '';
        $label = trim((string) $name . ($city !== '' && $city !== $name ? ', ' . $city : ''));

        vv_json([
            'success' => true,
            'result' => [
                'id' => 'pos-' . round($lat, 4) . '-' . round($lon, 4),
                'name' => $label !== '' ? $label : 'Din posisjon',
                'lat' => $lat,
                'lon' => $lon,
                'source' => 'user',
                'provider' => 'nominatim',
            ],
        ], 200, 'public, max-age=900');
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

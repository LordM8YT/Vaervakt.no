<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/../config.php';

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 80, 'UTF-8');
    } else {
        $query = substr($query, 0, 80);
    }

    if ($query === '' || strlen($query) < 2) {
        throw new InvalidArgumentException('Skriv minst to tegn for å søke etter sted.');
    }

    $cacheKey = 'vaervakt_geocode_' . sha1(function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query)) . '.json';
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey;
    if (is_readable($cachePath) && filemtime($cachePath) !== false && time() - (int) filemtime($cachePath) < 3600) {
        $cached = file_get_contents($cachePath);
        if ($cached !== false) {
            echo $cached;
            exit;
        }
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'jsonv2',
        'addressdetails' => '1',
        'limit' => '5',
        'countrycodes' => 'no',
        'accept-language' => 'nb-NO,nb,no,en',
    ]);

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
        throw new RuntimeException('Stedsøket svarte ikke.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stedsøket ga uventet respons.');
    }

    $results = [];
    foreach ($decoded as $item) {
        $lat = isset($item['lat']) ? (float) $item['lat'] : null;
        $lon = isset($item['lon']) ? (float) $item['lon'] : null;
        if ($lat === null || $lon === null) continue;

        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $name = trim((string) ($address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? $item['name'] ?? $query));
        $county = trim((string) ($address['county'] ?? $address['state'] ?? ''));
        $label = trim($name . ($county !== '' && stripos($name, $county) === false ? ', ' . $county : ''));
        if ($label === '') {
            $label = trim((string) ($item['display_name'] ?? $query));
        }

        $results[] = [
            'id' => 'search-' . substr(sha1($label . '|' . $lat . '|' . $lon), 0, 12),
            'name' => $label,
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
        ];
    }

    $payload = json_encode([
        'success' => true,
        'query' => $query,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents($cachePath, $payload, LOCK_EX);
    echo $payload;
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 502);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Kunne ikke søke etter sted akkurat nå.',
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
}

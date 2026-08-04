<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_met_alert_event_map(string $event): array
{
    $normalized = strtolower(trim($event));

    $map = [
        'forestfire' => ['label' => 'Skogbrannfare', 'icon' => '🔥', 'kind' => 'fire'],
        'forest_fire' => ['label' => 'Skogbrannfare', 'icon' => '🔥', 'kind' => 'fire'],
        'wildfire' => ['label' => 'Skogbrannfare', 'icon' => '🔥', 'kind' => 'fire'],
        'lightning' => ['label' => 'Tordenfare', 'icon' => '⚡', 'kind' => 'thunder'],
        'thunderstorm' => ['label' => 'Tordenfare', 'icon' => '⚡', 'kind' => 'thunder'],
        'rain' => ['label' => 'Kraftig regn', 'icon' => '🌧️', 'kind' => 'rain'],
        'rainflood' => ['label' => 'Regnflom', 'icon' => '🌊', 'kind' => 'flood'],
        'flood' => ['label' => 'Flomfare', 'icon' => '🌊', 'kind' => 'flood'],
        'wind' => ['label' => 'Sterk vind', 'icon' => '💨', 'kind' => 'wind'],
        'gale' => ['label' => 'Kraftig vind', 'icon' => '💨', 'kind' => 'wind'],
        'snow' => ['label' => 'Snøfare', 'icon' => '❄️', 'kind' => 'snow'],
        'blowingsnow' => ['label' => 'Snøfokk', 'icon' => '❄️', 'kind' => 'snow'],
        'ice' => ['label' => 'Glatt føre', 'icon' => '🧊', 'kind' => 'ice'],
        'icing' => ['label' => 'Ising', 'icon' => '🧊', 'kind' => 'ice'],
        'stormsurge' => ['label' => 'Stormflo', 'icon' => '🌊', 'kind' => 'flood'],
        'polarlow' => ['label' => 'Polart lavtrykk', 'icon' => '🌀', 'kind' => 'storm'],
    ];

    return $map[$normalized] ?? [
        'label' => $event !== '' ? $event : 'Farevarsel',
        'icon' => '⚠️',
        'kind' => 'warning',
    ];
}

function vv_met_alert_severity(array $properties): string
{
    $color = strtolower((string) ($properties['riskMatrixColor'] ?? $properties['awareness_level'] ?? ''));

    if (str_contains($color, 'red')) return 'danger';
    if (str_contains($color, 'orange')) return 'warning';
    if (str_contains($color, 'yellow')) return 'notice';

    $severity = strtolower((string) ($properties['severity'] ?? ''));
    if (in_array($severity, ['extreme', 'severe'], true)) return 'danger';
    if ($severity === 'moderate') return 'warning';

    return 'notice';
}

function vv_met_clean_title(string $headline, string $fallback): string
{
    $headline = trim($headline);
    if ($headline === '') return $fallback;

    $parts = array_values(array_filter(array_map('trim', explode(',', $headline)), static fn ($part) => $part !== ''));
    $title = $parts[0] ?? $headline;

    $title = (string) preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?\b/u', '', $title);
    $title = trim($title, " \t\n\r\0\x0B,-–");

    return $title !== '' ? $title : $fallback;
}

function vv_met_headline_times(string $headline): array
{
    if ($headline === '') return ['', ''];

    preg_match_all(
        '/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?\b/u',
        $headline,
        $matches
    );

    $times = $matches[0] ?? [];
    return [
        (string) ($times[0] ?? ''),
        (string) ($times[1] ?? ''),
    ];
}

function vv_met_fetch_time(array $properties, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($properties[$key] ?? ''));
        if ($value !== '') return $value;
    }

    return '';
}

function vv_fetch_met_alerts(float $lat, float $lon): array
{
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vv2_metalerts_' . round($lat, 2) . '_' . round($lon, 2) . '.json';

    if (is_readable($cache) && filemtime($cache) !== false && time() - (int) filemtime($cache) < 300) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = 'https://api.met.no/weatherapi/metalerts/2.0/current.json?lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lon);
    $data = vv_http_get_json($url, [], 10);
    @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $data;
}

try {
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);

    if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        vv_error('Koordinatene ser ikke gyldige ut.');
    }

    $data = vv_fetch_met_alerts($lat, $lon);
    $features = is_array($data['features'] ?? null) ? $data['features'] : [];
    $alerts = [];

    foreach ($features as $feature) {
        if (!is_array($feature)) continue;
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        $event = (string) (
            $properties['event'] ??
            $properties['eventAwarenessName'] ??
            $properties['awareness_type'] ??
            ''
        );

        $mapped = vv_met_alert_event_map($event);
        $rawTitle = trim((string) ($properties['headline'] ?? $properties['title'] ?? $mapped['label']));
        $title = vv_met_clean_title($rawTitle, $mapped['label']);
        [$headlineStart, $headlineEnd] = vv_met_headline_times($rawTitle);

        $startsAt = vv_met_fetch_time($properties, [
            'onset', 'effective', 'startsAt', 'startTime', 'validFrom', 'valid_from', 'start_time'
        ]);
        $endsAt = vv_met_fetch_time($properties, [
            'expires', 'ends', 'endsAt', 'endTime', 'validTo', 'valid_to', 'end_time'
        ]);

        // Noen MET-varsler har tidsperioden kun innebygd i headline.
        // Bruk den som fallback, men vis den aldri rått i tittelen.
        if ($startsAt === '') $startsAt = $headlineStart;
        if ($endsAt === '') $endsAt = $headlineEnd;

        $description = trim((string) ($properties['description'] ?? $properties['consequences'] ?? ''));
        $instruction = trim((string) ($properties['instruction'] ?? $properties['recommendation'] ?? ''));
        $area = trim((string) ($properties['area'] ?? $properties['areaDesc'] ?? ''));

        $alerts[] = [
            'id' => (string) ($feature['id'] ?? $properties['id'] ?? sha1($title . $event . $area)),
            'event' => $event,
            'title' => $title,
            'label' => $mapped['label'],
            'icon' => $mapped['icon'],
            'kind' => $mapped['kind'],
            'severity' => vv_met_alert_severity($properties),
            'riskMatrixColor' => (string) ($properties['riskMatrixColor'] ?? ''),
            'description' => $description,
            'instruction' => $instruction,
            'area' => $area,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'source' => 'MET',
        ];
    }

    vv_json([
        'success' => true,
        'count' => count($alerts),
        'alerts' => $alerts,
        'source' => 'Meteorologisk institutt',
    ], 200, 'public, max-age=300');
} catch (Throwable $error) {
    error_log('met alerts failed: ' . $error->getMessage());
    vv_error('Kunne ikke hente farevarsler akkurat nå.', 502);
}

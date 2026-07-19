<?php
declare(strict_types=1);

require_once __DIR__ . '/bath-location-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    vv_error('Metoden støttes ikke.', 405);
}

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '' || vv_len($query) < 2) {
    vv_json([
        'success' => true,
        'locations' => [],
        'count' => 0,
    ], 200, 'public, max-age=60');
}

if (vv_len($query) > 80) {
    vv_error('Søket er for langt.');
}

try {
    $locations = vv_bath_search_locations($query, 8);
    vv_json([
        'success' => true,
        'locations' => $locations,
        'count' => count($locations),
        'source' => 'Yr',
    ], 200, 'public, max-age=300');
} catch (Throwable $error) {
    error_log('bath location search failed: ' . $error->getMessage());
    vv_error('Yr kunne ikke søke etter badeplasser akkurat nå.', 502);
}

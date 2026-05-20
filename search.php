<?php
// Proxy-endepunkt for Nominatim autocomplete for å unngå CORS
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

$limit = intval($_GET['limit'] ?? 6);
$limit = $limit > 0 && $limit <= 20 ? $limit : 6;

$u = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
    'format' => 'jsonv2',
    'q' => $q,
    'addressdetails' => 1,
    'limit' => $limit,
]);

// Bruker den korrekte vaarvakt-eposten i headeren for å unngå blokkering
$opts = [
    "http" => [
        "header" => "User-Agent: Vaervakt.no/1.0 (patrick@vaarvakt.no)\r\n",
        "timeout" => 5
    ]
];
$context = stream_context_create($opts);

$resp = @file_get_contents($u, false, $context);
if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream error']);
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
    $data = [];
}

$out = [];
foreach ($data as $item) {
    $out[] = [
        'display' => $item['display_name'] ?? ($item['name'] ?? ''),
        'lat' => $item['lat'] ?? null,
        'lon' => $item['lon'] ?? null,
        'type' => $item['type'] ?? null,
        'class' => $item['class'] ?? null,
    ];
}

echo json_encode($out);
<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lon = isset($_GET['lon']) ? floatval($_GET['lon']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 25; // km

if ($lat === null || $lon === null) {
    http_response_code(400);
    echo json_encode(['error' => 'lat and lon required']);
    exit;
}

// Sjekker hvilken tabell som faktisk eksisterer i databasen med eksplisitt typehinting for Intelephense
function tableExists(PDO $pdo, string $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

$table = null;
$latCol = 'latitude';
$lonCol = 'longitude';
$selectCols = [];

if (tableExists($pdo, 'weather_reports')) {
    $table = 'weather_reports';
    $selectCols = ['username', 'weather_condition', 'location', 'temperature', 'created_at'];
} elseif (tableExists($pdo, 'reports')) {
    $table = 'reports';
    $selectCols = ['reporter_name AS username', 'conditions AS weather_condition', 'location', 'temperature_c AS temperature', 'created_at'];
}

if (!$table) {
    echo json_encode([]);
    exit;
}

// Haversine-formel i SQL for å finne målinger i nærheten
$sql = "SELECT " . implode(', ', $selectCols) . ", $latCol AS latitude, $lonCol AS longitude,
    (6371 * acos(
        cos(radians(:lat)) * cos(radians($latCol)) * cos(radians($lonCol) - radians(:lon)) +
        sin(radians(:lat)) * sin(radians($latCol))
    )) AS distance
    FROM $table
    WHERE $latCol IS NOT NULL AND $lonCol IS NOT NULL
    HAVING distance <= :radius
    ORDER BY distance ASC
    LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':lat' => $lat, ':lon' => $lon, ':radius' => $radius]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array_values($rows));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db error', 'msg' => $e->getMessage()]);
}
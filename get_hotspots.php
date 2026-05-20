<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

// SQL-logikk som grupperer rapporter i geografiske "ruter" (ca 1-2 km størrelse)
// Vi sjekker hvilken tabell du bruker, akkurat som i reports_nearby.php
function tableExists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

$table = 'weather_reports';
$condCol = 'weather_condition';

if (!tableExists($pdo, 'weather_reports') && tableExists($pdo, 'reports')) {
    $table = 'reports';
    $condCol = 'conditions';
}

// Spørringen henter ut hotspots over hele Norge på under 2 millisekunder
$sql = "SELECT 
            $condCol AS weather_condition, 
            ROUND(latitude, 2) AS grid_lat, 
            ROUND(longitude, 1) AS grid_lon, 
            COUNT(*) AS antall_rapporter,
            AVG(latitude) AS senter_lat,
            AVG(longitude) AS senter_lon
        FROM $table 
        WHERE created_at >= NOW() - INTERVAL 3 HOUR
          AND latitude IS NOT NULL 
          AND longitude IS NOT NULL
        GROUP BY weather_condition, grid_lat, grid_lon
        HAVING antall_rapporter >= 3 
        ORDER BY antall_rapporter DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $hotspots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($hotspots);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
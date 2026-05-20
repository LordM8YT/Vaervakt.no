<?php
require_once 'db.php';
$pdo = get_db_connection();

// 1. Hent og rens data (Sikkerhet først)
$user = htmlspecialchars(substr($_GET['user'] ?? 'Anonym', 0, 20));
$temp = filter_var($_GET['temp'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$type = $_GET['type'] ?? 'cloud';
$loc  = htmlspecialchars(substr($_GET['loc'] ?? 'Ukjent', 0, 30));

// Koordinater fra GPS-en i nettleseren
$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : NULL;
$lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : NULL;

// Ekstra data (Vann/Snø) hvis du har lagt til disse i DB
$snow  = !empty($_GET['snow_depth']) ? (int)$_GET['snow_depth'] : NULL;
$water = !empty($_GET['water_temp']) ? (float)$_GET['water_temp'] : NULL;

// 2. Validering: Vi lagrer bare hvis vi har et navn og en temperatur
if ($user && $temp !== false) {
    try {
        $sql = "INSERT INTO reports (
                    reporter_name, 
                    temperature_c, 
                    weather_icon, 
                    location, 
                    latitude, 
                    longitude, 
                    snow_depth, 
                    water_temp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user, $temp, $type, $loc, $lat, $lon, $snow, $water]);
        
    } catch (Exception $e) {
        // Logg feil her hvis nødvendig, f.eks. error_log($e->getMessage());
    }
}

// 3. Send brukeren tilbake til hovedsiden med koordinatene intakt
// Dette gjør at index.php umiddelbart viser de lokale dataene igjen
$redirect = "index.php";
if ($lat && $lon) {
    $redirect .= "?lat=$lat&lon=$lon";
}

header("Location: $redirect");
exit();
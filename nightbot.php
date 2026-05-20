<?php
require_once 'db.php';
$pdo = get_db_connection();

$user = $_GET['user'] ?? 'Anonym';
$query = $_GET['query'] ?? '';
$args = explode(' ', trim($query));

// 1. Vis siste vær hvis de bare skriver !vær
if (empty($query)) {
    $latest = $pdo->query("SELECT * FROM reports ORDER BY id DESC LIMIT 1")->fetch();
    echo "Siste rapport: " . $latest['location'] . " " . round($latest['temperature_c']) . "°C av " . $latest['reporter_name'] . " 🌡️";
    exit;
}

// 2. Hjelp-kommando
if ($args[0] === 'hjelp') {
    echo "Bruk: !vær [sted] [temp] [type]. Eks: !vær Grim 12 sol";
    exit;
}

// 3. Lagre ny rapport (Format: !vær Grim 12 sol)
if (count($args) >= 3) {
    $loc = htmlspecialchars($args[0]);
    $temp = floatval($args[1]);
    $type = htmlspecialchars($args[2]);

    $sql = "INSERT INTO reports (reporter_name, temperature_c, weather_icon, location) VALUES (?, ?, ?, ?)";
    $pdo->prepare($sql)->execute([$user, $temp, $type, $loc]);

    echo "✅ Registrert! $temp°C og $type på $loc. Sjekk værvakt.no!";
} else {
    echo "Prøv: !vær [sted] [temp] [type] eller '!vær hjelp'.";
}
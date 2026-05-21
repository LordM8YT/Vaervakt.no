<?php
require_once 'db.php';
require_once 'functions.php';

$pdo = get_db_connection();

// Dine nøyaktige koordinater ved Grim
$lat = 58.1502;
$lon = 7.9526;

// Hent MET-data
$forecast = get_met_forecast($lat, $lon);
$now = $forecast['properties']['timeseries'][0]['data']['instant']['details'] ?? [];
$next_1h = $forecast['properties']['timeseries'][0]['data']['next_1_hours']['details'] ?? [];

$air_temp = isset($now['air_temperature']) ? round($now['air_temperature']) : '--';
$wind_speed = isset($now['wind_speed']) ? round($now['wind_speed']) : '--';

// Henter siste rapport fra databasen
$latest = $pdo->query("SELECT * FROM reports ORDER BY id DESC LIMIT 1")->fetch();

// FALLBACK: Hvis det ikke finnes rapporter i DB ennå, lag en fake en så siden ikke dør
if (!$latest) {
    $latest = [
        'weather_icon' => 'cloud', 
        'temperature_c' => $air_temp, 
        'location' => 'Kristiansand', 
        'reporter_name' => 'System'
    ];
}

$icons = ['sun'=>'sun','sun_cloud'=>'cloud-sun','cloud'=>'cloud','rain'=>'cloud-rain','snow'=>'snowflake','thunder'=>'zap'];
$current_icon = $icons[$latest['weather_icon']] ?? 'cloud';
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/vendor/lucide/lucide.min.js"></script>
    <style>
        body { background: transparent !important; overflow: hidden; font-family: 'Inter', sans-serif; margin: 0; padding: 10px; }
        
        .widget-box {
            width: 260px;
            background: rgba(15, 23, 42, 0.95); 
            backdrop-filter: blur(12px);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-left: 5px solid #38bdf8;
            border-radius: 1rem;
            padding: 16px;
            color: white;
            box-shadow: 0 15px 30px rgba(0,0,0,0.6);
            position: relative;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            position: absolute;
            top: 15px;
            right: 15px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(1.4); }
            100% { opacity: 1; transform: scale(1); }
        }

        .data-label { font-size: 9px; font-weight: 900; color: #38bdf8; text-transform: uppercase; letter-spacing: 1.5px; }
    </style>
</head>
<body>

    <div class="widget-box">
        <div class="live-dot"></div>
        
        <div class="flex items-center gap-4 mb-4">
            <div class="bg-sky-500/20 p-2 rounded-xl">
                <i data-lucide="<?= $current_icon ?>" class="w-10 h-10 text-white"></i>
            </div>
            <div>
                <p class="data-label">Grim Torv</p>
                <h1 class="text-4xl font-black tracking-tighter"><?= $air_temp ?>&deg;C</h1>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 border-y border-white/10 py-3 mb-3">
            <div class="flex items-center gap-2">
                <i data-lucide="wind" class="w-4 h-4 text-sky-400"></i>
                <span class="text-xs font-bold"><?= $wind_speed ?> m/s</span>
            </div>
            <div class="flex items-center gap-2">
                <i data-lucide="umbrella" class="w-4 h-4 text-sky-400"></i>
                <span class="text-xs font-bold"><?= $next_1h['precipitation_amount'] ?? 0 ?> mm</span>
            </div>
        </div>

        <?php if($latest): ?>
        <div class="bg-white/5 p-2.5 rounded-lg border border-white/5">
            <p class="data-label mb-1" style="font-size: 7px;">Siste bruker-rapport</p>
            <div class="text-[11px] leading-snug">
                <span class="font-black text-sky-300"><?= htmlspecialchars($latest['reporter_name']) ?>:</span>
                <span class="text-white/80 italic">"<?= htmlspecialchars($latest['location']) ?> - <?= round($latest['temperature_c']) ?>&deg;"</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
        // Oppdaterer hvert 5. minutt
        setTimeout(() => { window.location.reload(); }, 300000);
    </script>
</body>
</html>
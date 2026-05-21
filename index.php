<?php
require_once 'db.php';

$defaultLat = 58.1504;
$defaultLon = 7.9470;
$lat = floatval($_GET['lat'] ?? $defaultLat);
$lon = floatval($_GET['lon'] ?? $defaultLon);
$supportUrl = defined('SUPPORT_URL') ? trim((string) SUPPORT_URL) : '';
$supportReady = $supportUrl !== '';
$supportLabel = defined('SUPPORT_LABEL') && trim((string) SUPPORT_LABEL) !== '' ? trim((string) SUPPORT_LABEL) : 'Støtt med Vipps';
$pushReady = defined('VAPID_PUBLIC') && trim((string) VAPID_PUBLIC) !== '';

function loadPatchnotes($filePath) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $notes = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $title = trim((string)($entry['title'] ?? ''));
        $date = trim((string)($entry['date'] ?? ''));
        if ($title === '' || $date === '') {
            continue;
        }

        $items = [];
        if (isset($entry['items']) && is_array($entry['items'])) {
            foreach ($entry['items'] as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
        }

        $notes[] = [
            'title' => $title,
            'date' => $date,
            'summary' => trim((string)($entry['summary'] ?? '')),
            'tag' => trim((string)($entry['tag'] ?? '')),
            'items' => $items,
        ];
    }

    usort($notes, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $notes;
}

function tableExistsLocal($pdo, $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

function fetchWeatherReportsForUi($pdo, $limit = 15) {
    $limit = max(1, min(100, (int)$limit));
    $response = [
        'rows' => [],
        'has_coords' => false,
    ];

    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");

    if (tableExistsLocal($pdo, 'weather_reports')) {
        $colStmt->execute(['weather_reports']);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        $select = ['username', 'weather_condition', 'location', 'temperature', 'created_at'];
        if (in_array('latitude', $cols, true)) {
            $select[] = 'latitude';
        }
        if (in_array('longitude', $cols, true)) {
            $select[] = 'longitude';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM weather_reports ORDER BY created_at DESC LIMIT ' . $limit;
        $response['rows'] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $response['has_coords'] = in_array('latitude', $cols, true) && in_array('longitude', $cols, true);
        return $response;
    }

    if (tableExistsLocal($pdo, 'reports')) {
        $colStmt->execute(['reports']);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        $select = ['reporter_name AS username', 'conditions AS weather_condition', 'location', 'temperature_c AS temperature', 'created_at'];
        if (in_array('latitude', $cols, true)) {
            $select[] = 'latitude';
        }
        if (in_array('longitude', $cols, true)) {
            $select[] = 'longitude';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM reports ORDER BY created_at DESC LIMIT ' . $limit;
        $response['rows'] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $response['has_coords'] = in_array('latitude', $cols, true) && in_array('longitude', $cols, true);
    }

    return $response;
}

function weatherEmoji($type) {
    $raw = trim((string)$type);
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    if ($normalized === '') {
        return '🌤️';
    }
    if (strpos($normalized, 'snø') !== false || strpos($normalized, 'sno') !== false) {
        return '❄️';
    }
    if (strpos($normalized, 'regn') !== false || strpos($normalized, 'rain') !== false || strpos($normalized, 'byge') !== false) {
        return '🌧️';
    }
    if (strpos($normalized, 'vind') !== false || strpos($normalized, 'storm') !== false) {
        return '⛈️';
    }
    if (strpos($normalized, 'tåke') !== false || strpos($normalized, 'taake') !== false || strpos($normalized, 'fog') !== false) {
        return '🌫️';
    }
    if (strpos($normalized, 'sky') !== false || strpos($normalized, 'cloud') !== false) {
        return '☁️';
    }
    return '☀️';
}

function formatRelativeTimeLabel($timestamp) {
    if (!$timestamp) {
        return 'Nå nettopp';
    }

    try {
        $created = new DateTime($timestamp);
        $now = new DateTime('now');
        $seconds = max(0, $now->getTimestamp() - $created->getTimestamp());
    } catch (Exception $e) {
        return 'Nylig';
    }

    if ($seconds < 45) {
        return 'Nå nettopp';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min siden';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' t siden';
    }
    if ($seconds < 604800) {
        return floor($seconds / 86400) . ' d siden';
    }

    return $created->format('d.m H:i');
}


function buildHourlyForecastForUi($data, $limit = 9) {
    $series = $data['properties']['timeseries'] ?? [];
    $items = [];
    foreach (array_slice($series, 0, $limit) as $i => $entry) {
        $temp = $entry['data']['instant']['details']['air_temperature'] ?? null;
        $time = $entry['time'] ?? '';
        try {
            $label = $i === 0 ? 'Nå' : (new DateTime($time))->format('H');
        } catch (Exception $e) {
            $label = $i === 0 ? 'Nå' : '+' . $i . 't';
        }
        $items[] = [
            'label' => $label,
            'temp' => is_numeric($temp) ? (string)round((float)$temp) : '--',
        ];
    }
    return $items ?: [['label' => 'Nå', 'temp' => '--']];
}

function buildRainBarsForUi($data, $limit = 12) {
    $series = $data['properties']['timeseries'] ?? [];
    $amounts = [];
    foreach (array_slice($series, 0, $limit) as $i => $entry) {
        $amount = $entry['data']['next_1_hours']['details']['precipitation_amount']
            ?? $entry['data']['next_6_hours']['details']['precipitation_amount']
            ?? 0;
        $amounts[] = max(0, (float)$amount);
    }
    if (!$amounts) {
        $amounts = array_fill(0, $limit, 0);
    }
    $max = max($amounts) ?: 1;
    $bars = [];
    foreach ($amounts as $i => $amount) {
        $bars[] = [
            'height' => max(8, (int)round(($amount / $max) * 100)),
            'amount' => number_format($amount, 1, ',', ''),
            'label' => $i === 0 ? 'Nå' : '+' . $i . 't',
        ];
    }
    return $bars;
}

function formatDayLabelForUi($date) {
    $labels = ['SØN', 'MAN', 'TIR', 'ONS', 'TOR', 'FRE', 'LØR'];
    if ($date instanceof DateTime) {
        return $labels[(int)$date->format('w')] ?? strtoupper($date->format('D'));
    }
    return 'DAG';
}

function buildDailyForecastForUi($data, $limit = 5) {
    $series = $data['properties']['timeseries'] ?? [];
    $days = [];
    foreach ($series as $entry) {
        $time = $entry['time'] ?? '';
        try {
            $dt = new DateTime($time);
        } catch (Exception $e) {
            continue;
        }
        $key = $dt->format('Y-m-d');
        if (!isset($days[$key])) {
            $days[$key] = ['date' => $dt, 'temps' => [], 'rain' => 0.0];
        }
        $temp = $entry['data']['instant']['details']['air_temperature'] ?? null;
        if (is_numeric($temp)) {
            $days[$key]['temps'][] = (float)$temp;
        }
        $days[$key]['rain'] += (float)($entry['data']['next_1_hours']['details']['precipitation_amount'] ?? 0);
    }
    $out = [];
    foreach (array_slice($days, 0, $limit, true) as $day) {
        $temps = $day['temps'] ?: [0];
        $low = (int)round(min($temps));
        $high = (int)round(max($temps));
        $range = min(100, max(18, ($high - $low + 2) * 12));
        $out[] = [
            'label' => formatDayLabelForUi($day['date']),
            'low' => $low,
            'high' => $high,
            'rain' => number_format($day['rain'], 1, ',', ''),
            'range' => $range,
        ];
    }
    return $out ?: [['label' => 'I DAG', 'low' => 0, 'high' => 0, 'rain' => '0,0', 'range' => 20]];
}

function formatPatchnoteDateLabel($dateString) {
    if (!$dateString) {
        return '';
    }

    try {
        $date = new DateTime($dateString);
        return $date->format('d.m.Y');
    } catch (Exception $e) {
        return (string)$dateString;
    }
}

$latestReportsPayload = fetchWeatherReportsForUi($pdo, 15);
$latestReports = $latestReportsPayload['rows'];
$mapReportsPayload = fetchWeatherReportsForUi($pdo, 50);
$mapReports = $mapReportsPayload['rows'];
$reportsHaveCoords = (bool)$mapReportsPayload['has_coords'];
$patchnotes = loadPatchnotes(__DIR__ . DIRECTORY_SEPARATOR . 'patchnotes.json');
$latestPatchnote = $patchnotes ? $patchnotes[0] : null;

// Hent værdata fra MET.no med feilhåndtering
$temp_now = '--';
$symbol = 'clearsky_day';
$api_error = false;

$api_url = "https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=$lat&lon=$lon";
$opts = ["http" => ["header" => "User-Agent: Vaervakt.no/1.0 (patrick@vaarvakt.no)", "timeout" => 5]];
$context = stream_context_create($opts);

try {
    $response = @file_get_contents($api_url, false, $context);
    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && isset($data['properties']['timeseries'][0])) {
            $temp_now = round($data['properties']['timeseries'][0]['data']['instant']['details']['air_temperature'] ?? 0);
            $symbol = $data['properties']['timeseries'][0]['data']['next_1_hours']['summary']['symbol_code'] ?? 'clearsky_day';
        } else {
            $api_error = true;
        }
    } else {
        $api_error = true;
    }
} catch (Exception $e) {
    $api_error = true;
    error_log('MET.no API error: ' . $e->getMessage());
}

$hourlyForecast = isset($data) ? buildHourlyForecastForUi($data) : buildHourlyForecastForUi([]);
$rainBars = isset($data) ? buildRainBarsForUi($data) : buildRainBarsForUi([]);
$dailyForecast = isset($data) ? buildDailyForecastForUi($data) : buildDailyForecastForUi([]);
$currentDetails = $data['properties']['timeseries'][0]['data']['instant']['details'] ?? [];
$currentWind = isset($currentDetails['wind_speed']) ? round((float)$currentDetails['wind_speed'] * 3.6) : null;
$currentHumidity = isset($currentDetails['relative_humidity']) ? round((float)$currentDetails['relative_humidity']) : null;
$rainSoon = 0.0;
foreach (array_slice($rainBars, 0, 4) as $bar) {
    $rainSoon += (float)str_replace(',', '.', (string)$bar['amount']);
}
$rainSummary = $rainSoon > 0 ? 'Nedbør mulig snart' : 'Rolig akkurat nå';
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#0b0f1a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Værvakt.no</title>
    
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/vendor/leaflet.markercluster/MarkerCluster.css">
    <link rel="stylesheet" href="assets/vendor/leaflet.markercluster/MarkerCluster.Default.css">
</head>
<body class="vv-body">

    <div id="infoModal" class="modal p-6" onclick="closeModal('infoModal')" aria-hidden="true">
        <div class="glass-card p-10 rounded-[2.5rem] max-w-sm w-full text-center" onclick="event.stopPropagation()">
            <h2 class="text-xl font-black italic uppercase text-sky-400 mb-4">VÆRVAKT INFO</h2>
            <p class="text-slate-300 text-sm leading-relaxed">
                Værvakt.no er en app der folk kan melde inn været de ser og varsle naboer, venner og familier om fare knyttet til været.
            </p>
            <button onclick="closeModal('infoModal')" class="mt-8 bg-sky-500 w-full py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-sky-500/20">LUKK</button>
        </div>
    </div>

    <?php if ($patchnotes): ?>
    <div id="patchnotesModal" class="modal p-4 sm:p-6" onclick="closeModal('patchnotesModal')" aria-hidden="true">
        <div class="glass-card modal-panel w-full max-w-3xl rounded-[2.5rem] border border-white/10 shadow-2xl" onclick="event.stopPropagation()" role="dialog" aria-modal="true" aria-labelledby="patchnotesTitle">
            <div class="flex items-center justify-between gap-4 border-b border-white/10 px-6 py-5 sm:px-8">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-sky-400">Patchnotes</p>
                    <h2 id="patchnotesTitle" class="mt-2 text-xl font-black text-white sm:text-2xl">Hva som er nytt i Vaervakt</h2>
                </div>
                <button type="button" onclick="closeModal('patchnotesModal')" class="rounded-2xl border border-white/10 bg-slate-950/50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.18em] text-slate-300">Lukk</button>
            </div>
            <div class="modal-scroll space-y-4 px-4 py-4 sm:px-6 sm:py-6">
                <?php foreach ($patchnotes as $note): ?>
                    <article class="patchnote-entry rounded-[2rem] border border-white/8 p-5 sm:p-6">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border border-sky-400/20 bg-sky-500/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-sky-200"><?= htmlspecialchars(formatPatchnoteDateLabel($note['date'])) ?></span>
                            <?php if (!empty($note['tag'])): ?>
                                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-slate-300"><?= htmlspecialchars($note['tag']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="mt-4 text-lg font-black text-white sm:text-xl"><?= htmlspecialchars($note['title']) ?></h3>
                        <?php if (!empty($note['summary'])): ?>
                            <p class="mt-2 text-sm leading-relaxed text-slate-300"><?= htmlspecialchars($note['summary']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($note['items'])): ?>
                            <div class="mt-4 grid gap-3">
                                <?php foreach ($note['items'] as $item): ?>
                                    <div class="patchnote-accent rounded-[1.5rem] border border-white/6 px-4 py-3 text-sm leading-relaxed text-slate-200"><?= htmlspecialchars($item) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="vv-device-shell">
        <div class="vv-dynamic-island" aria-hidden="true"></div>
        <div class="vv-gradient-sky" aria-hidden="true"></div>
        <main class="vv-app-scroll" id="top">
            <?php include __DIR__ . '/app/Views/partials/weather-search-actions.php'; ?>
            <?php include __DIR__ . '/app/Views/partials/weather-hero.php'; ?>
            <?php include __DIR__ . '/app/Views/partials/weather-forecast.php'; ?>
            <?php include __DIR__ . '/app/Views/partials/weather-map.php'; ?>
            <?php include __DIR__ . '/app/Views/partials/weather-observations.php'; ?>
            <?php include __DIR__ . '/app/Views/partials/weather-support-patchnotes.php'; ?>
        </main>
        <?php include __DIR__ . '/app/Views/partials/weather-report-sheet.php'; ?>
        <?php include __DIR__ . '/app/Views/partials/weather-bottom-nav.php'; ?>
    </div>
    <script src="assets/vendor/lucide/lucide.min.js"></script>
    <script src="assets/vendor/leaflet/leaflet.js"></script>
    <script src="assets/vendor/leaflet.markercluster/leaflet.markercluster.js"></script>
    <script src="assets/js/app-core.js"></script>
        <?php $forecastJson = isset($data) ? $data : null; ?>
        <script>
            window.VAERVAKT_CONFIG = {
                vapidPublicKey: <?= json_encode(defined('VAPID_PUBLIC') ? VAPID_PUBLIC : '') ?>,
                forecastData: <?= json_encode($forecastJson) ?>,
                reportsData: <?= json_encode($mapReports) ?>,
                reportsHaveCoords: <?= json_encode((bool)$reportsHaveCoords) ?>,
                lat: <?= json_encode($lat) ?>,
                lon: <?= json_encode($lon) ?>,
                symbol: <?= json_encode($symbol) ?>,
                tempNow: <?= json_encode($temp_now) ?>
            };
        </script>
        <script src="assets/js/pwa-push.js"></script>
        <script src="assets/js/map.js"></script>
</body>
</html>

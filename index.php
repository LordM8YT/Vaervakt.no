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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    
    <style>
        html { min-height: 100%; }
        body { background-color: #0b0f1a; color: white; font-family: sans-serif; min-height: 100svh; overflow-x: hidden; overscroll-behavior: none; padding-bottom: env(safe-area-inset-bottom, 0); position: relative; isolation: isolate; }
        .glass-card { background: rgba(22, 30, 45, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.05); }
        .map-glow { border: 2px solid #38bdf8; box-shadow: 0 0 30px rgba(56, 189, 248, 0.2); }
        input, select { background: rgba(2, 6, 23, 0.8) !important; border: 1px solid rgba(255,255,255,0.05) !important; color: white !important; outline: none; font-size: 16px !important; }
        input::placeholder { color: #94a3b8; }
        button { -webkit-tap-highlight-color: transparent; }
        .sr-only-trap { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid white; border-radius: 50%; animation: spin 0.8s linear infinite; }
        .small-spinner { display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,0.25);border-top:2px solid white;border-radius:50%;animation:spin 0.8s linear infinite;margin-left:8px }
        .status-hint { min-height: 1.25rem; }
        .obs-item { transition: background-color 220ms ease, border-color 220ms ease, transform 220ms ease, box-shadow 220ms ease; }
        .obs-item.is-fresh { border-color: rgba(56, 189, 248, 0.45); background: rgba(14, 165, 233, 0.14); box-shadow: 0 14px 36px rgba(14, 165, 233, 0.12); animation: freshPulse 2.2s ease; }
        .feed-status { transition: background-color 200ms ease, color 200ms ease, border-color 200ms ease; }
        .feed-status[data-tone="success"] { background: rgba(16, 185, 129, 0.14); color: #a7f3d0; border-color: rgba(16, 185, 129, 0.2); }
        .feed-status[data-tone="warning"] { background: rgba(245, 158, 11, 0.14); color: #fde68a; border-color: rgba(245, 158, 11, 0.2); }
        .feed-status[data-tone="neutral"] { background: rgba(56, 189, 248, 0.1); color: #bae6fd; border-color: rgba(56, 189, 248, 0.18); }
        .support-card { position: relative; overflow: hidden; }
        .support-card::after { content: ""; position: absolute; inset: auto -20% -35% auto; width: 220px; height: 220px; border-radius: 9999px; background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, rgba(255,255,255,0) 70%); pointer-events: none; }
        .support-card.is-pending { border-color: rgba(148, 163, 184, 0.14); }
        .modal-panel { max-height: min(82svh, 860px); overflow: hidden; }
        .modal-scroll { overflow-y: auto; }
        .patchnote-accent { background: linear-gradient(135deg, rgba(56, 189, 248, 0.18), rgba(14, 165, 233, 0.04)); }
        .patchnote-entry { background: rgba(2, 6, 23, 0.45); }
        header, main { position: relative; z-index: 1; }
        #mapContainer, #leafletMap, .leaflet-container { position: relative; z-index: 0; isolation: isolate; }
        .leaflet-pane, .leaflet-top, .leaflet-bottom, .leaflet-control { z-index: 10 !important; }
        @keyframes freshPulse { 0% { transform: translateY(-3px); } 35% { transform: translateY(0); } 100% { transform: translateY(0); } }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="flex flex-col">

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

    <header class="p-6 text-center flex flex-col items-center">
        <h1 class="text-3xl font-black tracking-tighter text-sky-400 italic uppercase mb-4">VÆRVAKT.NO</h1>
        <div class="w-full max-w-xl">
            <div class="relative mb-4">
                <input id="placeSearch" type="search" placeholder="Søk på sted eller koordinater" class="w-full p-3 rounded-2xl text-sm" aria-label="Søk sted">
                <div id="searchResults" class="absolute left-0 right-0 mt-2 bg-white/5 backdrop-blur rounded-xl max-h-60 overflow-auto" style="display:none; z-index:1100;"></div>
            </div>
        </div>
        <div class="glass-card bg-slate-900/40 border border-white/5 px-6 py-6 rounded-[1.5rem] w-full text-center">
            <p id="pushStatus" class="text-slate-300 text-xs tracking-wide"><?= $pushReady ? 'Push-varsler: ikke abonnert' : 'Push-varsler: kommer snart' ?></p>
            <div class="flex flex-wrap gap-3 justify-center mt-3">
                <button id="pushBtn" <?= $pushReady ? '' : 'disabled' ?> class="<?= $pushReady ? 'bg-sky-500' : 'bg-slate-800 text-slate-400 cursor-not-allowed' ?> px-4 py-2 rounded-2xl font-black text-xs uppercase tracking-widest"><?= $pushReady ? 'Aktiver varsler' : 'Varsler kommer snart' ?></button>
                <button id="installBtn" class="hidden bg-slate-700 px-4 py-2 rounded-2xl font-black text-xs uppercase tracking-widest">Installer app</button>
                <?php if ($patchnotes): ?>
                    <button type="button" onclick="openModal('patchnotesModal')" class="bg-slate-800 px-4 py-2 rounded-2xl font-black text-xs uppercase tracking-widest">Patchnotes</button>
                <?php endif; ?>
                <button id="shareBtn" class="bg-slate-800 px-4 py-2 rounded-2xl font-black text-xs uppercase tracking-widest">Del appen</button>
            </div>
        </div>
    </header>

    <main class="px-4 max-w-4xl mx-auto space-y-6 flex-1 pb-40 w-full">
        <div class="glass-card p-8 rounded-[2.5rem] flex items-center justify-around shadow-2xl">
            <div id="weatherIcon" style="min-width: 80px; display: flex; align-items: center; justify-content: center;">
                <?php if ($api_error): ?>
                    <span class="spinner"></span>
                <?php else: ?>
                    <img src="https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/<?= $symbol ?>.svg" class="w-20 h-20" alt="Værikon">
                <?php endif; ?>
            </div>
            <div class="text-left">
                <span class="text-7xl font-black italic" id="tempDisplay"><?= $temp_now ?>°</span>
                <p class="text-sky-500 text-[10px] font-bold uppercase tracking-widest text-center">Lokal Status</p>
            </div>
        </div>

        <div id="mapContainer" class="map-glow rounded-[2.5rem] overflow-hidden h-80 relative">
            <div id="leafletMap" style="width:100%;height:100%"></div>
        </div>

        <div class="glass-card p-8 rounded-[2.5rem] shadow-xl border border-white/5">
            <h3 class="text-[10px] font-black text-slate-500 uppercase mb-6 tracking-[0.2em] text-center italic">Ny observasjon</h3>
            <form id="reportForm" action="save.php" method="POST" onsubmit="handleSubmit(event)" class="space-y-4">
                <label for="userInput" class="sr-only">Ditt navn</label>
                <input id="userInput" type="text" name="user" required placeholder="Ditt navn" class="w-full p-4 rounded-2xl text-sm" aria-label="Ditt navn" autocomplete="nickname" maxlength="50">
                <div class="sr-only-trap" aria-hidden="true">
                    <label for="companyWebsite">Nettside</label>
                    <input id="companyWebsite" type="text" name="company_website" tabindex="-1" autocomplete="off">
                </div>
                <div class="relative">
                    <select id="weatherInput" name="weather_type" required class="w-full p-4 rounded-2xl text-sm appearance-none cursor-pointer" aria-label="Værtype">
                        <option value="">-- Velg værtype --</option>
                        <option value="Sol">☀️ Sol / Klart</option>
                        <option value="Skyet">☁️ Overskyet</option>
                        <option value="Regn">🌧️ Regn / Byger</option>
                        <option value="Snø">❄️ Snø / Sludd</option>
                        <option value="Tåke">🌫️ Tåke</option>
                        <option value="Vind">💨 Kraftig vind / Storm</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <label for="locInput" class="sr-only">Sted</label>
                    <input id="locInput" type="text" name="loc" required placeholder="Sted eller nabolag" class="sm:col-span-3 p-4 rounded-2xl text-sm" aria-label="Sted" autocomplete="address-level2" maxlength="100">
                    <label for="tempInput" class="sr-only">Temperatur</label>
                    <input id="tempInput" type="number" step="0.1" name="temp" required placeholder="°C" class="sm:col-span-1 p-4 rounded-2xl text-sm text-center" aria-label="Temperatur" inputmode="decimal">
                </div>
                <div class="flex flex-col gap-3 rounded-[1.5rem] bg-slate-950/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Auto-posisjon</p>
                        <p id="locationAssistText" class="status-hint mt-1 text-sm text-slate-300">Trykk for å hente sted automatisk, også utenfor Norge.</p>
                    </div>
                    <button type="button" id="useLocationBtn" class="shrink-0 rounded-2xl bg-slate-800 px-4 py-3 text-xs font-black uppercase tracking-widest text-white">Bruk min posisjon</button>
                </div>
                <input type="hidden" name="form_started_at" value="">
                <div class="flex items-center gap-2">
                    <button type="button" id="addFavoriteBtn" class="px-4 py-2 bg-slate-800 rounded-2xl text-xs">Legg til favoritt</button>
                    <select id="favoritesSelect" class="p-3 rounded-2xl text-sm text-slate-300 bg-slate-900/30">
                        <option value="">Velg favoritt</option>
                    </select>
                </div>
                <input type="hidden" name="lat" id="formLat" value="<?= htmlspecialchars($_GET['lat'] ?? '') ?>">
                <input type="hidden" name="lon" id="formLon" value="<?= htmlspecialchars($_GET['lon'] ?? '') ?>">
                <button id="submitBtn" type="submit" class="w-full bg-sky-500 hover:bg-sky-400 py-5 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg active:scale-95 transition-transform flex items-center justify-center gap-2">
                    <span id="submitText">Send værrapport</span>
                </button>
            </form>
        </div>

        <div class="glass-card support-card <?= $supportReady ? '' : 'is-pending' ?> p-6 rounded-[2.5rem] shadow-xl border border-white/5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="max-w-xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-sky-400">Støtt Værvakt</p>
                        <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-slate-300">
                            <?= $supportReady ? 'Vipps klar' : 'Vipps på vei' ?>
                        </span>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed text-slate-300">
                        <?= $supportReady
                            ? 'Bidrag hjelper oss med drift, push-varsler og bedre lokal værdekning uten å fylle appen med reklame.'
                            : 'Vipps-lenken er snart klar. Når den er godkjent dukker støtteknappen opp her med én gang.' ?>
                    </p>
                </div>
                <?php if ($supportReady): ?>
                    <a href="<?= htmlspecialchars($supportUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-2xl bg-sky-500 px-5 py-3 text-xs font-black uppercase tracking-widest text-slate-950">
                        <?= htmlspecialchars($supportLabel) ?>
                    </a>
                <?php else: ?>
                    <button type="button" disabled class="inline-flex items-center justify-center rounded-2xl border border-white/10 bg-slate-900/60 px-5 py-3 text-xs font-black uppercase tracking-widest text-slate-400 cursor-not-allowed">
                        Vipps kommer snart
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($latestPatchnote): ?>
        <section class="glass-card rounded-[2.5rem] border border-white/5 p-6 shadow-xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="max-w-2xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-sky-400">Nyeste patchnote</p>
                        <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-slate-300"><?= htmlspecialchars(formatPatchnoteDateLabel($latestPatchnote['date'])) ?></span>
                        <?php if (!empty($latestPatchnote['tag'])): ?>
                            <span class="rounded-full border border-sky-400/20 bg-sky-500/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-sky-200"><?= htmlspecialchars($latestPatchnote['tag']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="mt-3 text-xl font-black text-white sm:text-2xl"><?= htmlspecialchars($latestPatchnote['title']) ?></h3>
                    <?php if (!empty($latestPatchnote['summary'])): ?>
                        <p class="mt-2 text-sm leading-relaxed text-slate-300"><?= htmlspecialchars($latestPatchnote['summary']) ?></p>
                    <?php endif; ?>
                </div>
                <button type="button" onclick="openModal('patchnotesModal')" class="inline-flex items-center justify-center rounded-2xl bg-slate-900/70 px-5 py-3 text-xs font-black uppercase tracking-widest text-white">Se alle patchnotes</button>
            </div>
            <?php if (!empty($latestPatchnote['items'])): ?>
                <div class="mt-4 grid gap-3">
                    <?php foreach (array_slice($latestPatchnote['items'], 0, 3) as $item): ?>
                        <div class="patchnote-accent rounded-[1.5rem] border border-white/6 px-4 py-3 text-sm leading-relaxed text-slate-200"><?= htmlspecialchars($item) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <div class="glass-card p-8 rounded-[2.5rem] shadow-xl border border-white/5">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h3 id="obsTitle" class="text-[10px] font-black text-sky-500 uppercase tracking-widest text-center italic">Siste observasjoner</h3>
                <p id="feedStatusPill" data-tone="neutral" class="feed-status rounded-full border border-sky-400/20 px-3 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-sky-200">Live nå</p>
            </div>
            <div id="observationList" class="space-y-4">
                <?php
                if (!$latestReports) {
                    echo '<p id="noObservationsMsg" class="text-xs text-slate-500 italic py-4 text-center">Ingen observasjoner enda...</p>';
                } else {
                    foreach ($latestReports as $row) {
                        $type = $row['weather_condition'] ?? '';
                        $emoji = weatherEmoji($type);
                        $timeLabel = formatRelativeTimeLabel($row['created_at'] ?? null);
                        
                        echo '<div class="obs-item flex items-center justify-between gap-4 bg-slate-950/30 p-4 rounded-2xl border border-white/5" data-type="' . htmlspecialchars($type) . '">';
                        echo '  <div class="flex min-w-0 items-center gap-4 text-left">';
                        echo '    <div class="text-3xl leading-none" aria-hidden="true">' . $emoji . '</div>';
                        echo '    <div class="min-w-0">';
                        echo '      <p class="text-[10px] uppercase font-black tracking-[0.16em] text-slate-500">' . htmlspecialchars($timeLabel) . '</p>';
                        echo '      <p class="truncate text-sm font-bold">' . htmlspecialchars($row['username']) . ' i ' . htmlspecialchars($row['location']) . '</p>';
                        echo '      <p class="text-[10px] uppercase font-black text-sky-400">' . htmlspecialchars($type) . '</p>';
                        echo '    </div>';
                        echo '  </div>';
                        echo '  <div class="shrink-0 text-right font-black italic text-xl">' . round((float)$row['temperature']) . '°</div>';
                        echo '</div>';
                    }
                }
                ?>
                <p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>
            </div>
            <button id="resetFilter" onclick="filterWeather('all')" class="hidden mt-6 text-center w-full text-[9px] uppercase font-bold text-slate-500 tracking-widest">Gå tilbake til oversikt</button>
        </div>
    </main>

    <nav class="fixed bottom-0 left-0 right-0 bg-slate-950/90 backdrop-blur-2xl border-t border-white/10 px-6 py-6 z-[1200]" style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));">
        <div class="flex justify-around items-center max-w-md mx-auto">
            <button onclick="filterWeather('all')" id="nav-all" class="flex flex-col items-center text-sky-400">
                <i data-lucide="layout-dashboard" class="w-6 h-6"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase">Oversikt</span>
            </button>
            <button onclick="filterWeather('vann')" id="nav-vann" class="flex flex-col items-center text-slate-500 transition-colors">
                <i data-lucide="waves" class="w-6 h-6"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase">Flom/Snø</span>
            </button>
            <button onclick="openModal('infoModal')" class="flex flex-col items-center text-slate-500">
                <i data-lucide="shield-check" class="w-6 h-6"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase">Info</span>
            </button>
        </div>
    </nav>

    <script>
        lucide.createIcons();

        function openModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.modal.active')) {
                document.documentElement.style.overflow = '';
                document.body.style.overflow = '';
            }
        }

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.modal.active').forEach((modal) => {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
            });
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        });

        const SUBMIT_DEFAULT_LABEL = 'Send værrapport';

        let serviceWorkerRegistrationPromise = null;

        function getServiceWorkerRegistration() {
            if (!('serviceWorker' in navigator)) {
                return Promise.reject(new Error('Service Worker ikke støttet'));
            }

            if (!serviceWorkerRegistrationPromise) {
                serviceWorkerRegistrationPromise = navigator.serviceWorker
                    .register('/service-worker.js', { scope: '/' })
                    .then((registration) => navigator.serviceWorker.ready.then(() => registration))
                    .catch((error) => {
                        serviceWorkerRegistrationPromise = null;
                        throw error;
                    });
            }

            return serviceWorkerRegistrationPromise;
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (match) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            }[match]));
        }

        function getWeatherEmoji(type) {
            const normalized = String(type || '').toLowerCase();
            if (normalized.includes('snø') || normalized.includes('sno')) return '❄️';
            if (normalized.includes('regn') || normalized.includes('rain') || normalized.includes('byge')) return '🌧️';
            if (normalized.includes('vind') || normalized.includes('storm')) return '⛈️';
            if (normalized.includes('tåke') || normalized.includes('taake') || normalized.includes('fog')) return '🌫️';
            if (normalized.includes('sky') || normalized.includes('cloud')) return '☁️';
            return '☀️';
        }

        function formatRelativeTimeLabel(dateString) {
            if (!dateString) return 'Nå nettopp';
            const ts = new Date(dateString);
            if (Number.isNaN(ts.getTime())) return 'Nylig';
            const diffSeconds = Math.max(0, Math.round((Date.now() - ts.getTime()) / 1000));
            if (diffSeconds < 45) return 'Nå nettopp';
            if (diffSeconds < 3600) return `${Math.max(1, Math.floor(diffSeconds / 60))} min siden`;
            if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} t siden`;
            if (diffSeconds < 604800) return `${Math.floor(diffSeconds / 86400)} d siden`;
            return ts.toLocaleString('nb-NO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        }

        function renderObservationCard(report) {
            const type = report.weather_condition || report.weather || '';
            const temperatureRaw = report.temperature ?? report.temp ?? 0;
            const temperature = Number(temperatureRaw);
            const username = report.username || report.user || 'Noen';
            const location = report.location || report.loc || 'Ukjent sted';
            const createdAt = report.created_at || new Date().toISOString();

            return `
                <div class="obs-item flex items-center justify-between gap-4 bg-slate-950/30 p-4 rounded-2xl border border-white/5" data-type="${escapeHtml(type)}">
                    <div class="flex min-w-0 items-center gap-4 text-left">
                        <div class="text-3xl leading-none" aria-hidden="true">${getWeatherEmoji(type)}</div>
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase font-black tracking-[0.16em] text-slate-500">${escapeHtml(formatRelativeTimeLabel(createdAt))}</p>
                            <p class="truncate text-sm font-bold">${escapeHtml(username)} i ${escapeHtml(location)}</p>
                            <p class="text-[10px] uppercase font-black text-sky-400">${escapeHtml(type)}</p>
                        </div>
                    </div>
                    <div class="shrink-0 text-right font-black italic text-xl">${Math.round(Number.isFinite(temperature) ? temperature : 0)}°</div>
                </div>
            `;
        }

        function prependObservationCard(report) {
            const list = document.getElementById('observationList');
            if (!list) return;
            const empty = document.getElementById('noObservationsMsg');
            if (empty) empty.remove();
            list.insertAdjacentHTML('afterbegin', renderObservationCard(report));
            if (!document.getElementById('emptyFilterMsg')) {
                list.insertAdjacentHTML('beforeend', '<p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>');
            }
            const fresh = list.querySelector('.obs-item');
            if (fresh) {
                fresh.classList.add('is-fresh');
                setTimeout(() => fresh.classList.remove('is-fresh'), 2600);
            }
            const items = list.querySelectorAll('.obs-item');
            if (items.length > 15) {
                items[items.length - 1].remove();
            }
        }

        let feedStatusResetTimer = null;

        function setFeedStatus(message, tone = 'neutral', options = {}) {
            const pill = document.getElementById('feedStatusPill');
            if (!pill) return;
            pill.dataset.tone = tone;
            pill.textContent = message;
            if (feedStatusResetTimer) {
                clearTimeout(feedStatusResetTimer);
                feedStatusResetTimer = null;
            }
            if (options.autoReset !== false && tone !== 'neutral') {
                feedStatusResetTimer = setTimeout(() => {
                    pill.dataset.tone = 'neutral';
                    pill.textContent = 'Live nå';
                }, options.resetAfter ?? 4200);
            }
        }

        function setLocationAssist(message, tone = 'neutral') {
            const el = document.getElementById('locationAssistText');
            if (!el) return;
            const tones = {
                neutral: 'text-slate-300',
                success: 'text-emerald-300',
                warning: 'text-amber-300',
                error: 'text-rose-300',
            };
            el.className = `status-hint mt-1 text-sm ${tones[tone] || tones.neutral}`;
            el.textContent = message;
        }

        function getFriendlyGeolocationError(error) {
            if (!error || typeof error.code === 'undefined') {
                return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
            }
            if (error.code === error.PERMISSION_DENIED) {
                return 'Posisjon ble avslått. Skriv stedet manuelt eller tillat GPS.';
            }
            if (error.code === error.POSITION_UNAVAILABLE) {
                return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
            }
            if (error.code === error.TIMEOUT) {
                return 'Posisjonstjenesten brukte for lang tid. Prøv igjen eller skriv stedet manuelt.';
            }
            return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
        }

        function requestCurrentPosition(options = {}) {
            return new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    reject(new Error('Geolocation unsupported'));
                    return;
                }
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: options.enableHighAccuracy ?? true,
                    timeout: options.timeout ?? 7000,
                    maximumAge: options.maximumAge ?? 120000,
                });
            });
        }

        function preferredLanguage() {
            if (Array.isArray(navigator.languages) && navigator.languages.length) {
                return navigator.languages[0];
            }
            return navigator.language || 'nb-NO';
        }

        function buildLocationLabel(address) {
            if (!address) return '';
            const primary = [
                address.suburb,
                address.neighbourhood,
                address.neighborhood,
                address.borough,
                address.residential,
                address.quarter,
                address.city_district,
                address.village,
                address.hamlet,
                address.town,
                address.city,
            ].find(Boolean);
            const secondary = [
                address.city,
                address.town,
                address.municipality,
                address.county,
                address.state_district,
                address.state,
                address.province,
                address.region,
                address.country,
            ].find((value) => value && value !== primary);

            if (primary && secondary) return `${primary}, ${secondary}`;
            return primary || secondary || address.country || '';
        }

        async function reverseGeocode(lat, lon) {
            const url = new URL('https://nominatim.openstreetmap.org/reverse');
            url.search = new URLSearchParams({
                format: 'jsonv2',
                lat: String(lat),
                lon: String(lon),
                zoom: '13',
                'accept-language': preferredLanguage(),
            }).toString();

            const res = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                throw new Error(`Reverse geocode failed: ${res.status}`);
            }
            const data = await res.json();
            return buildLocationLabel(data.address || {}) || data.display_name || '';
        }

        async function hydrateLocationFromCoords(lat, lon, options = {}) {
            const locInput = document.getElementById('locInput');
            const latInput = document.getElementById('formLat');
            const lonInput = document.getElementById('formLon');
            const shouldUpdateField = options.updateField !== false;
            const shouldToast = options.toastOnSuccess === true;

            if (latInput) latInput.value = lat;
            if (lonInput) lonInput.value = lon;

            try {
                const label = await reverseGeocode(lat, lon);
                if (label && locInput && (shouldUpdateField || !locInput.value.trim())) {
                    locInput.value = label;
                }
                if (label) {
                    setLocationAssist(`Fant ${label}.`, 'success');
                    if (shouldToast) showToast('Sted oppdatert');
                } else {
                    setLocationAssist('Fant posisjon, men ikke sted. Du kan skrive stedet manuelt.', 'warning');
                }
                return label;
            } catch (error) {
                console.warn('Reverse geocoding feilet:', error);
                setLocationAssist('Fant posisjon, men ikke sted. Du kan skrive stedet manuelt.', 'warning');
                return '';
            }
        }

        async function handleUseCurrentLocation() {
            const button = document.getElementById('useLocationBtn');
            if (button) button.disabled = true;
            setLocationAssist('Henter posisjon …', 'neutral');

            try {
                const pos = await requestCurrentPosition({ timeout: 8000, enableHighAccuracy: true });
                const lat = pos.coords.latitude;
                const lon = pos.coords.longitude;
                await hydrateLocationFromCoords(lat, lon, { updateField: true, toastOnSuccess: true });
                if (window.__vaervakt_map) {
                    window.__vaervakt_map.setView([lat, lon], 11);
                }
                if (typeof window.fetchReportsNearby === 'function') {
                    window.fetchReportsNearby(lat, lon);
                }
                setFeedStatus('Ser på området ditt', 'neutral', { autoReset: false });
                const url = new URL(window.location.href);
                url.searchParams.set('lat', lat);
                url.searchParams.set('lon', lon);
                window.history.replaceState({}, '', url.toString());
            } catch (error) {
                const message = getFriendlyGeolocationError(error);
                setLocationAssist(message, 'error');
                showToast(message);
            } finally {
                if (button) button.disabled = false;
            }
        }

        function resetSubmitButton() {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            if (submitBtn) submitBtn.disabled = false;
            if (submitText) submitText.innerHTML = SUBMIT_DEFAULT_LABEL;
        }

        function stampReportFormStart() {
            const form = document.getElementById('reportForm');
            if (!form) return;
            const field = form.querySelector('input[name="form_started_at"]');
            if (field) {
                field.value = String(Math.floor(Date.now() / 1000));
            }
        }

        function setPushUiState(state, message) {
            const pushBtn = document.getElementById('pushBtn');
            const pushStatus = document.getElementById('pushStatus');
            if (!pushBtn || !pushStatus) return;

            const disabled = state === 'active' || state === 'unsupported' || state === 'busy' || state === 'denied';
            pushBtn.disabled = disabled;
            pushBtn.classList.toggle('bg-sky-500', !disabled);
            pushBtn.classList.toggle('bg-slate-800', disabled);
            pushBtn.classList.toggle('text-slate-400', disabled);
            pushBtn.classList.toggle('cursor-not-allowed', disabled);

            if (state === 'active') {
                pushBtn.textContent = 'Varsler aktivert';
                pushStatus.textContent = 'Push-varsler: abonnert';
                return;
            }
            if (state === 'busy') {
                pushBtn.textContent = 'Aktiverer...';
                pushStatus.textContent = 'Push-varsler: aktiverer';
                return;
            }
            if (state === 'denied') {
                pushBtn.textContent = 'Varsler blokkert';
                pushStatus.textContent = message || 'Push-varsler: blokkert i nettleseren';
                return;
            }
            if (state === 'unsupported') {
                pushBtn.textContent = 'Push ikke støttet';
                pushStatus.textContent = message || 'Push-varsler: ikke støttet';
                return;
            }

            pushBtn.textContent = 'Aktiver varsler';
            pushStatus.textContent = message || 'Push-varsler: ikke abonnert';
        }

        async function syncPushUi() {
            if (!PUSH_NOTIFICATIONS_READY || !('PushManager' in window)) {
                setPushUiState('unsupported', PUSH_NOTIFICATIONS_READY ? 'Push-varsler: ikke støttet' : 'Push-varsler: kommer snart');
                return;
            }

            try {
                const reg = await getServiceWorkerRegistration();
                const existing = await reg.pushManager.getSubscription();
                if (existing) {
                    setPushUiState('active');
                    return;
                }

                if ('permissions' in navigator && typeof navigator.permissions.query === 'function') {
                    try {
                        const permission = await navigator.permissions.query({ name: 'notifications' });
                        if (permission.state === 'denied') {
                            setPushUiState('denied');
                            return;
                        }
                    } catch (permissionError) {
                        // Enkelte nettlesere støtter PushManager uten Permissions API for notifications.
                    }
                }

                setPushUiState('ready');
            } catch (error) {
                console.warn('Kunne ikke lese push-status:', error);
                setPushUiState('unsupported', 'Push-varsler: kunne ikke klargjøres');
            }
        }

        async function handleSubmit(event) {
            event.preventDefault();
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            submitBtn.disabled = true;
            submitText.innerHTML = '<span class="spinner"></span> Sender...';

            const form = document.getElementById('reportForm');
            const userInput = document.getElementById('userInput');
            const weatherInput = document.getElementById('weatherInput');
            const locInput = document.getElementById('locInput');
            const tempInput = document.getElementById('tempInput');
            const honeypotInput = document.getElementById('companyWebsite');
            const formStartedAtInput = form.querySelector('input[name="form_started_at"]');
            const latInput = document.getElementById('formLat');
            const lonInput = document.getElementById('formLon');
            const user = userInput.value.trim();
            const weather = weatherInput.value;
            const temp = tempInput.value;
            let loc = locInput.value.trim();

            if (!user || !weather || temp === '') {
                showToast('Fyll inn navn, værtype og temperatur.');
                setFeedStatus('Mangler felt', 'warning');
                resetSubmitButton();
                return;
            }

            if ((!latInput.value || !lonInput.value) && navigator.geolocation) {
                try {
                    const pos = await requestCurrentPosition({ timeout: 5000, enableHighAccuracy: false });
                    await hydrateLocationFromCoords(pos.coords.latitude, pos.coords.longitude, { updateField: !loc, toastOnSuccess: false });
                    loc = locInput.value.trim();
                } catch (error) {
                    if (!loc) {
                        const message = getFriendlyGeolocationError(error);
                        setLocationAssist(message, 'error');
                        showToast(message);
                        setFeedStatus('Posisjon mangler', 'warning');
                        resetSubmitButton();
                        return;
                    }
                }
            }

            if (!loc) {
                showToast('Legg til sted eller bruk posisjon før du sender.');
                setFeedStatus('Sted mangler', 'warning');
                resetSubmitButton();
                return;
            }

            const fd = new FormData();
            fd.append('user', user);
            fd.append('weather_type', weather);
            fd.append('loc', loc);
            fd.append('temp', temp);
            fd.append('company_website', honeypotInput ? honeypotInput.value : '');
            fd.append('form_started_at', formStartedAtInput ? formStartedAtInput.value : '');
            if (latInput && latInput.value) fd.append('lat', latInput.value);
            if (lonInput && lonInput.value) fd.append('lon', lonInput.value);

            try {
                const res = await fetch('save.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const json = await res.json().catch(() => null);
                if (res.ok && json && json.success) {
                    const savedReport = json.report || {
                        username: user,
                        weather_condition: weather,
                        location: loc,
                        temperature: temp,
                        created_at: new Date().toISOString(),
                        latitude: latInput && latInput.value ? latInput.value : null,
                        longitude: lonInput && lonInput.value ? lonInput.value : null,
                    };

                    prependObservationCard(savedReport);
                    if (typeof window.addMapReportMarker === 'function') {
                        window.addMapReportMarker(savedReport, true);
                    }
                    if (navigator.vibrate) {
                        navigator.vibrate(12);
                    }

                    localStorage.setItem('vaervakt_username', user);
                    if (loc) {
                        localStorage.setItem('vaervakt_last_location', loc);
                    }

                    const preservedUser = user;
                    const preservedLocation = loc;
                    const preservedLat = latInput ? latInput.value : '';
                    const preservedLon = lonInput ? lonInput.value : '';

                    form.reset();
                    userInput.value = preservedUser;
                    locInput.value = preservedLocation;
                    if (latInput) latInput.value = preservedLat;
                    if (lonInput) lonInput.value = preservedLon;
                    weatherInput.value = '';
                    tempInput.value = '';
                    stampReportFormStart();

                    showToast('Værrapport sendt');
                    setFeedStatus('Ny rapport sendt', 'success');
                    setLocationAssist(preservedLocation ? `Klar med ${preservedLocation}.` : 'Trykk for å hente sted automatisk, også utenfor Norge.', preservedLocation ? 'success' : 'neutral');
                    resetSubmitButton();
                    return;
                }

                throw new Error((json && json.message) ? json.message : ((json && json.error) ? json.error : 'Server returned non-OK'));
            } catch (err) {
                const message = String((err && err.message) || '');
                const isLikelyOffline = !navigator.onLine || message.includes('Failed to fetch') || message.includes('NetworkError');
                if (!isLikelyOffline) {
                    console.error('Kunne ikke sende rapport:', err);
                    showToast(message || 'Kunne ikke lagre værrapporten akkurat nå.');
                    setFeedStatus('Kunne ikke sende', 'warning');
                    resetSubmitButton();
                    return;
                }

                const report = {
                    user,
                    weather,
                    loc,
                    temp: parseFloat(temp) || 0,
                    lat: (latInput && latInput.value) ? parseFloat(latInput.value) : null,
                    lon: (lonInput && lonInput.value) ? parseFloat(lonInput.value) : null,
                    created_at: new Date().toISOString(),
                    form_started_at: formStartedAtInput ? formStartedAtInput.value : '',
                };
                try {
                    await addToOutbox(report);
                    showToast('Ingen nett. Rapporten ligger i kø og sendes automatisk ved tilkobling.');
                    setFeedStatus('Lagret offline', 'warning');
                    updateQueueUI();
                } catch (e) {
                    console.error('Kunne ikke lagre i outbox:', e);
                    showToast('Kunne ikke lagre rapport lokalt.');
                    setFeedStatus('Lagring feilet', 'warning');
                }
                resetSubmitButton();
            }
        }

        function filterWeather(mode) {
            const items = document.querySelectorAll('.obs-item');
            const title = document.getElementById('obsTitle');
            const resetBtn = document.getElementById('resetFilter');
            const navAll = document.getElementById('nav-all');
            const navVann = document.getElementById('nav-vann');
            const emptyMsg = document.getElementById('emptyFilterMsg');
            let found = 0;

            items.forEach(item => {
                const type = item.getAttribute('data-type');
                if (mode === 'all') {
                    item.style.display = 'flex';
                    found++;
                } else {
                    if (['Regn', 'Snø', 'Vind'].includes(type)) {
                        item.style.display = 'flex';
                        found++;
                    } else {
                        item.style.display = 'none';
                    }
                }
            });

            if (mode === 'vann') {
                title.innerText = "Varslede vann- & snøforhold";
                resetBtn.classList.remove('hidden');
                navVann.classList.replace('text-slate-500', 'text-sky-400');
                navAll.classList.replace('text-sky-400', 'text-slate-500');
                if (emptyMsg) emptyMsg.style.display = (found === 0) ? 'block' : 'none';
            } else {
                title.innerText = "Siste observasjoner";
                resetBtn.classList.add('hidden');
                navAll.classList.replace('text-slate-500', 'text-sky-400');
                navVann.classList.replace('text-sky-400', 'text-slate-500');
                if (emptyMsg) emptyMsg.style.display = 'none';
            }
        }

        (function(){
            const input = document.getElementById('placeSearch');
            const results = document.getElementById('searchResults');
            let timer = null;
            const cache = new Map();
            function clearResults(){ results.style.display='none'; results.innerHTML=''; }
            function showResults(items){
                results.innerHTML = '';
                if (!items || !items.length) { clearResults(); return; }
                items.forEach(it => {
                    const el = document.createElement('button');
                    el.type = 'button';
                    el.className = 'w-full text-left p-3 hover:bg-white/10 border-b border-white/5';
                    el.innerHTML = `<div class="text-sm font-semibold">${it.display}</div><div class="text-[11px] text-slate-400">${it.type||''} ${it.class||''}</div>`;
                    el.addEventListener('click', ()=>{
                        input.value = it.display;
                        const locInput = document.getElementById('locInput');
                        const latInput = document.getElementById('formLat');
                        const lonInput = document.getElementById('formLon');
                        if (locInput) locInput.value = it.display;
                        if (latInput && it.lat) latInput.value = it.lat;
                        if (lonInput && it.lon) lonInput.value = it.lon;
                        clearResults();
                        if (it.lat && it.lon) {
                            if (window.__vaervakt_map) {
                                window.__vaervakt_map.setView([parseFloat(it.lat), parseFloat(it.lon)], 12);
                            } else {
                                window.location.href = `index.php?lat=${it.lat}&lon=${it.lon}`;
                                return;
                            }
                            fetchReportsNearby(it.lat, it.lon);
                        }
                    });
                    results.appendChild(el);
                });
                results.style.display = 'block';
            }

            const loader = document.createElement('span'); loader.className='small-spinner'; loader.style.display='none';
            input.parentNode.appendChild(loader);

            input && input.addEventListener('input', (e)=>{
                const q = e.target.value.trim();
                if (timer) clearTimeout(timer);
                if (!q) { clearResults(); loader.style.display='none'; return; }
                timer = setTimeout(async ()=>{
                    try {
                        if (cache.has(q)) { showResults(cache.get(q)); loader.style.display='none'; return; }
                        loader.style.display='inline-block';
                        const res = await fetch('search.php?q='+encodeURIComponent(q));
                        loader.style.display='none';
                        if (!res.ok) { clearResults(); return; }
                        const json = await res.json();
                        cache.set(q, json);
                        showResults(json);
                    } catch (e) { clearResults(); loader.style.display='none'; }
                }, 280);
            });
            document.addEventListener('click', (ev)=>{ if (!results.contains(ev.target) && ev.target !== input) clearResults(); });
        })();
    </script>

    <script>
        const VAPID_PUBLIC = <?= json_encode(defined('VAPID_PUBLIC') ? VAPID_PUBLIC : '') ?>;
        const PUSH_NOTIFICATIONS_READY = Boolean(VAPID_PUBLIC);

        function openDB() {
            return new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) return reject(new Error('IndexedDB ikke støttet'));
                const req = indexedDB.open('vaervakt', 1);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                };
                req.onsuccess = (e) => resolve(e.target.result);
                req.onerror = (e) => reject(e.target.error);
            });
        }

        async function addToOutbox(item) {
            const db = await openDB();
            return new Promise((res, rej) => {
                const tx = db.transaction('outbox', 'readwrite');
                const store = tx.objectStore('outbox');
                const r = store.add(item);
                r.onsuccess = () => res(r.result);
                r.onerror = () => rej(r.error);
            });
        }

        async function getOutbox() {
            try {
                const db = await openDB();
                return new Promise((res, rej) => {
                    const tx = db.transaction('outbox', 'readonly');
                    const store = tx.objectStore('outbox');
                    const req = store.getAll();
                    req.onsuccess = () => res(req.result || []);
                    req.onerror = () => rej(req.error);
                });
            } catch (e) { return []; }
        }

        async function deleteOutbox(id) {
            const db = await openDB();
            return new Promise((res, rej) => {
                const tx = db.transaction('outbox', 'readwrite');
                const store = tx.objectStore('outbox');
                const req = store.delete(id);
                req.onsuccess = () => res();
                req.onerror = () => rej(req.error);
            });
        }

        async function sendQueuedReports() {
            const items = await getOutbox();
            for (const item of items) {
                try {
                    const fd = new FormData();
                    fd.append('user', item.user);
                    fd.append('weather_type', item.weather);
                    fd.append('loc', item.loc);
                    fd.append('temp', item.temp);
                    fd.append('queued_replay', '1');
                    if (item.lat !== null && item.lat !== undefined && item.lat !== '') fd.append('lat', item.lat);
                    if (item.lon !== null && item.lon !== undefined && item.lon !== '') fd.append('lon', item.lon);
                    const res = await fetch('save.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (res.ok) {
                        const json = await res.json().catch(() => null);
                        if (json && json.success) {
                            await deleteOutbox(item.id);
                            if (json.report) {
                                prependObservationCard(json.report);
                                if (typeof window.addMapReportMarker === 'function') {
                                    window.addMapReportMarker(json.report, false);
                                }
                            }
                            showToast('Offline-rapport sendt.');
                            updateQueueUI();
                        }
                    }
                } catch (e) {
                    console.warn('Kunne ikke sende queued report:', e);
                }
            }
        }

        function showToast(msg, timeout = 3500) {
            const el = document.createElement('div');
            el.textContent = msg;
            el.style.position = 'fixed';
            el.style.left = '50%';
            el.style.transform = 'translateX(-50%)';
            el.style.bottom = 'calc(90px + env(safe-area-inset-bottom, 0px))';
            el.style.background = 'rgba(2,6,23,0.9)';
            el.style.color = 'white';
            el.style.padding = '8px 14px';
            el.style.borderRadius = '12px';
            el.style.zIndex = 2000;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), timeout);
        }

        async function updateQueueUI() {
            const items = await getOutbox();
            const pushStatus = document.getElementById('pushStatus');
            if (!pushStatus) return;
            const base = pushStatus.textContent.split('·')[0].trim();
            if (items.length) pushStatus.textContent = `${base} · Kø: ${items.length}`;
            else pushStatus.textContent = base;
        }

        function loadFavorites() {
            const sel = document.getElementById('favoritesSelect');
            if (!sel) return;
            sel.innerHTML = '<option value="">Velg favoritt</option>';
            const favs = JSON.parse(localStorage.getItem('vaervakt_favorites') || '[]');
            favs.forEach(f => {
                const opt = document.createElement('option');
                opt.value = JSON.stringify(f);
                opt.textContent = f.name;
                sel.appendChild(opt);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadFavorites();
            updateQueueUI();
            stampReportFormStart();
            syncPushUi();

            const rememberedUser = localStorage.getItem('vaervakt_username');
            const rememberedLocation = localStorage.getItem('vaervakt_last_location');
            const userInput = document.getElementById('userInput');
            const locInput = document.getElementById('locInput');
            const useLocationBtn = document.getElementById('useLocationBtn');
            const shareBtn = document.getElementById('shareBtn');

            if (userInput && rememberedUser && !userInput.value) {
                userInput.value = rememberedUser;
            }
            if (locInput && rememberedLocation && !locInput.value) {
                locInput.value = rememberedLocation;
            }
            if (useLocationBtn) {
                useLocationBtn.addEventListener('click', handleUseCurrentLocation);
            }
            if (shareBtn) {
                shareBtn.addEventListener('click', async () => {
                    const shareUrl = `${window.location.origin}${window.location.pathname}`;
                    if (navigator.share) {
                        try {
                            await navigator.share({
                                title: 'Værvakt.no',
                                text: 'Se lokale værobservasjoner på Værvakt.no',
                                url: shareUrl,
                            });
                            showToast('Takk for at du deler Værvakt');
                        } catch (error) {
                            if (error && error.name !== 'AbortError') {
                                showToast('Kunne ikke åpne deling akkurat nå.');
                            }
                        }
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(shareUrl);
                        showToast('Lenke kopiert');
                    } catch (error) {
                        showToast('Kunne ikke kopiere lenken.');
                    }
                });
            }

            setLocationAssist(locInput && locInput.value ? `Klar med ${locInput.value}.` : 'Trykk for å hente sted automatisk, også utenfor Norge.', locInput && locInput.value ? 'success' : 'neutral');

            const favSel = document.getElementById('favoritesSelect');
            if (favSel) favSel.addEventListener('change', (e) => {
                if (!e.target.value) return;
                const f = JSON.parse(e.target.value);
                document.getElementById('locInput').value = f.loc || '';
                if (f.lat !== null && f.lat !== undefined && f.lat !== '') {
                    document.getElementById('formLat').value = f.lat;
                    document.getElementById('formLon').value = f.lon;
                }
                setLocationAssist(f.loc ? `Klar med ${f.loc}.` : 'Favoritt lastet.', 'success');
            });

            const addFavBtn = document.getElementById('addFavoriteBtn');
            if (addFavBtn) addFavBtn.addEventListener('click', () => {
                const name = prompt('Navn for favoritt (f.eks. Hjem)');
                if (!name) return;
                const f = { name, loc: document.getElementById('locInput').value, lat: document.getElementById('formLat').value || null, lon: document.getElementById('formLon').value || null };
                const favs = JSON.parse(localStorage.getItem('vaervakt_favorites') || '[]');
                favs.push(f);
                localStorage.setItem('vaervakt_favorites', JSON.stringify(favs));
                loadFavorites();
                showToast('Favoritt lagret');
            });

            const pushBtn = document.getElementById('pushBtn');
            if (pushBtn) {
                pushBtn.onclick = PUSH_NOTIFICATIONS_READY ? registerPush : null;
            }

            const installBtn = document.getElementById('installBtn');
            let deferredPrompt = null;
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                if (installBtn) installBtn.style.display = 'inline-block';
            });
            if (installBtn) installBtn.addEventListener('click', async () => {
                if (!deferredPrompt) return showToast('Installeringsprompt ikke tilgjengelig');
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                showToast(choice.outcome === 'accepted' ? 'Installert' : 'Avvist');
                deferredPrompt = null;
            });

            sendQueuedReports();
        });

        window.addEventListener('online', () => { sendQueuedReports(); updateQueueUI(); });

        function urlBase64ToUint8Array(base64String) {
            const normalized = String(base64String || '').trim().replace(/\s/g, '');
            if (!normalized) {
                throw new Error('Mangler VAPID public key');
            }

            const remainder = normalized.length % 4;
            if (remainder === 1) {
                throw new Error('Ugyldig VAPID public key-lengde');
            }

            const padding = '='.repeat((4 - remainder) % 4);
            const base64 = (normalized + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            return Uint8Array.from(rawData, (char) => char.charCodeAt(0));
        }

        async function getPushPermissionState(registration, subscribeOptions) {
            if (registration.pushManager && typeof registration.pushManager.permissionState === 'function') {
                return registration.pushManager.permissionState(subscribeOptions);
            }

            if (typeof Notification !== 'undefined' && Notification.permission) {
                return Notification.permission === 'default' ? 'prompt' : Notification.permission;
            }

            return 'prompt';
        }

        async function ensurePushPermission(registration, subscribeOptions) {
            let state = await getPushPermissionState(registration, subscribeOptions);
            if (state === 'default') state = 'prompt';
            if (state === 'denied') return state;

            if (state === 'prompt' && typeof Notification !== 'undefined' && typeof Notification.requestPermission === 'function') {
                state = await Notification.requestPermission();
                if (state === 'default') state = 'prompt';
            }

            return state;
        }

        let pushSubscriptionInFlight = null;

        async function registerPush() {
            if (pushSubscriptionInFlight) {
                return pushSubscriptionInFlight;
            }

            pushSubscriptionInFlight = (async () => {
                if (!PUSH_NOTIFICATIONS_READY) {
                    showToast('Push-varsler blir aktivert når oppsettet er klart.');
                    return;
                }
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    setPushUiState('unsupported');
                    showToast('Push ikke støttet i denne nettleseren');
                    return;
                }

                setPushUiState('busy');

                try {
                    const applicationServerKey = urlBase64ToUint8Array(VAPID_PUBLIC);
                    const subscribeOptions = { userVisibleOnly: true, applicationServerKey };
                    const reg = await getServiceWorkerRegistration();
                    const existing = await reg.pushManager.getSubscription();
                    if (existing) {
                        setPushUiState('active');
                        showToast('Allerede abonnert');
                        return;
                    }

                    const permissionState = await ensurePushPermission(reg, subscribeOptions);
                    if (permissionState !== 'granted') {
                        setPushUiState(permissionState === 'denied' ? 'denied' : 'ready');
                        showToast(permissionState === 'denied' ? 'Push-varsler er blokkert i nettleseren' : 'Push-varsler ble ikke tillatt');
                        return;
                    }

                    const sub = await reg.pushManager.subscribe(subscribeOptions);
                    const res = await fetch('subscriptions.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ subscription: sub }),
                    });

                    if (res.ok) {
                        setPushUiState('active');
                        showToast('Abonnert på push-varsler');
                    } else {
                        setPushUiState('ready');
                        showToast('Kunne ikke lagre abonnement');
                    }
                } catch (error) {
                    console.error('Push-abonnement feilet:', error);
                    setPushUiState('ready');
                    showToast('Abonnement feilet');
                }
            })().finally(() => {
                pushSubscriptionInFlight = null;
            });

            return pushSubscriptionInFlight;
        }
    </script>
        <?php $forecastJson = isset($data) ? $data : null; ?>
        <script>
            const forecastData = <?= json_encode($forecastJson) ?>;
            const reportsData = <?= json_encode($mapReports) ?>;
            const reportsHaveCoords = <?= json_encode((bool)$reportsHaveCoords) ?>;
            (function(){
                const lat = <?= json_encode($lat) ?>;
                const lon = <?= json_encode($lon) ?>;
                const map = L.map('leafletMap', { zoomControl: false }).setView([lat, lon], 10);
                window.__vaervakt_map = map;
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

                function typeColor(t){
                    t = (t||'').toLowerCase();
                    if(t.includes('sn') || t.includes('snø')) return '#60a5fa';
                    if(t.includes('regn') || t.includes('rain')) return '#3b82f6';
                    if(t.includes('vind') || t.includes('storm')) return '#fb923c';
                    if(t.includes('tåke')) return '#94a3b8';
                    return '#34d399';
                }

                function makeReportMarker(report) {
                    const rlat = (report.latitude !== undefined && report.latitude !== null && report.latitude !== '') ? parseFloat(report.latitude) : null;
                    const rlon = (report.longitude !== undefined && report.longitude !== null && report.longitude !== '') ? parseFloat(report.longitude) : null;
                    if (!Number.isFinite(rlat) || !Number.isFinite(rlon)) {
                        return null;
                    }
                    const color = typeColor(report.weather_condition);
                    const html = `<div style="width:14px;height:14px;border-radius:9999px;background:${color};border:2px solid rgba(255,255,255,0.06)"></div>`;
                    const icon = L.divIcon({ html: html, className: '', iconSize: [18,18], iconAnchor: [9,9] });
                    const marker = L.marker([rlat, rlon], { icon: icon });
                    marker.bindPopup(`<strong>${escapeHtml(report.username)}</strong><br>${escapeHtml(report.location)}<br>${Math.round(report.temperature)}°<br><em>${escapeHtml(report.weather_condition)}</em>`);
                    return marker;
                }

                const markerCluster = L.markerClusterGroup();
                let plotted = false;
                let noCoordsControl = null;
                reportsData.forEach((r) => {
                    const marker = makeReportMarker(r);
                    if (marker) {
                        plotted = true;
                        markerCluster.addLayer(marker);
                    }
                });
                if (markerCluster.getLayers().length) map.addLayer(markerCluster);

                window.addMapReportMarker = function(report, panToMarker) {
                    const normalizedReport = {
                        ...report,
                        username: report.username || report.user || 'Noen',
                        location: report.location || report.loc || 'Ukjent sted',
                        weather_condition: report.weather_condition || report.weather || '',
                        temperature: report.temperature ?? report.temp ?? 0,
                        latitude: report.latitude ?? report.lat ?? null,
                        longitude: report.longitude ?? report.lon ?? null,
                    };
                    const marker = makeReportMarker(normalizedReport);
                    if (!marker) {
                        return;
                    }
                    markerCluster.addLayer(marker);
                    if (!map.hasLayer(markerCluster)) {
                        map.addLayer(markerCluster);
                    }
                    if (panToMarker) {
                        map.flyTo([parseFloat(normalizedReport.latitude), parseFloat(normalizedReport.longitude)], Math.max(map.getZoom(), 10), { duration: 0.6 });
                    }
                    if (noCoordsControl) {
                        map.removeControl(noCoordsControl);
                        noCoordsControl = null;
                    }
                };

                window.fetchReportsNearby = async function(latN, lonN, radiusKm=25) {
                    try {
                        const res = await fetch(`reports_nearby.php?lat=${encodeURIComponent(latN)}&lon=${encodeURIComponent(lonN)}&radius=${encodeURIComponent(radiusKm)}`);
                        if (!res.ok) return;
                        const rows = await res.json();
                        markerCluster.clearLayers();
                        const obsList = document.getElementById('observationList');
                        if (obsList) obsList.innerHTML = '';
                        let any = false;
                        for (const r of rows) {
                            const marker = makeReportMarker(r);
                            if (marker) {
                                any = true;
                                markerCluster.addLayer(marker);
                            }
                            if (obsList) {
                                obsList.insertAdjacentHTML('beforeend', renderObservationCard(r));
                            }
                        }
                        if (obsList && !rows.length) {
                            obsList.innerHTML = '<p id="noObservationsMsg" class="text-xs text-slate-500 italic py-4 text-center">Ingen observasjoner i dette området ennå.</p><p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>';
                        } else if (obsList && !document.getElementById('emptyFilterMsg')) {
                            obsList.insertAdjacentHTML('beforeend', '<p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>');
                        }
                        if (markerCluster.getLayers().length) {
                            if (!map.hasLayer(markerCluster)) map.addLayer(markerCluster);
                            try { map.fitBounds(markerCluster.getBounds().pad(0.2)); } catch(e){}
                            if (noCoordsControl) {
                                map.removeControl(noCoordsControl);
                                noCoordsControl = null;
                            }
                        }
                        if (!any) {
                            showToast('Ingen rapporter funnet i området');
                            setFeedStatus('Ingen rapporter i nærheten', 'warning');
                        } else {
                            setFeedStatus('Lokale rapporter oppdatert', 'success');
                        }
                    } catch (e) { console.error('fetchReportsNearby failed', e); }
                };

                if (forecastData && forecastData.properties && Array.isArray(forecastData.properties.timeseries) && forecastData.properties.timeseries.length > 0) {
                    const timeseries = forecastData.properties.timeseries;
                    const frames = timeseries.map(ts => {
                        const time = ts.time;
                        const temp = ts.data && ts.data.instant && ts.data.instant.details && (ts.data.instant.details.air_temperature !== undefined) ? Math.round(ts.data.instant.details.air_temperature) : null;
                        let symbol = null;
                        if (ts.data && ts.data.next_1_hours && ts.data.next_1_hours.summary && ts.data.next_1_hours.summary.symbol_code) symbol = ts.data.next_1_hours.summary.symbol_code;
                        else if (ts.data && ts.data.next_6_hours && ts.data.next_6_hours.summary && ts.data.next_6_hours.summary.symbol_code) symbol = ts.data.next_6_hours.summary.symbol_code;
                        else if (ts.data && ts.data.next_12_hours && ts.data.next_12_hours.summary && ts.data.next_12_hours.summary.symbol_code) symbol = ts.data.next_12_hours.summary.symbol_code;
                        return { time, temp, symbol, raw: ts };
                    });

                    const metIconAt = (symbol)=> L.icon({ iconUrl: `https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/${symbol || 'clearsky_day'}.svg`, iconSize: [56,56], iconAnchor: [28,28] });
                    const forecastMarker = L.marker([lat, lon], { icon: metIconAt(frames[0].symbol || '<?= $symbol ?>') }).addTo(map);
                    forecastMarker.bindPopup('');

                    const forecastControl = L.control({ position: 'bottomleft' });
                    forecastControl.onAdd = function(map){
                        const div = L.DomUtil.create('div', '');
                        div.style.minWidth = '260px';
                        div.style.padding = '8px';
                        div.style.borderRadius = '12px';
                        div.style.background = 'rgba(6,8,15,0.8)';
                        div.style.boxShadow = '0 6px 20px rgba(2,6,23,0.6)';
                        div.innerHTML = `
                            <div style="display:flex;align-items:center;gap:8px">
                                <button id="forecastPlay" style="background:#0ea5e9;color:white;border:none;padding:6px 10px;border-radius:10px;font-weight:700">▶</button>
                                <input id="timeSlider" type="range" min="0" max="${frames.length-1}" value="0" style="flex:1">
                            </div>
                            <div id="timeLabel" style="margin-top:6px;font-size:12px;color:#cbd5e1"></div>
                        `;
                        L.DomEvent.disableClickPropagation(div);
                        L.DomEvent.disableScrollPropagation(div);
                        return div;
                    };
                    forecastControl.addTo(map);

                    const slider = document.getElementById('timeSlider');
                    const label = document.getElementById('timeLabel');
                    const playBtn = document.getElementById('forecastPlay');
                    let playTimer = null;
                    let currentIndex = 0;

                    function updateFrame(i){
                        if (i < 0 || i >= frames.length) return;
                        currentIndex = i;
                        const f = frames[i];
                        forecastMarker.setIcon(metIconAt(f.symbol || 'clearsky_day'));
                        const t = (f.temp !== null) ? `${f.temp}°` : '—';
                        const timeStr = new Date(f.time).toLocaleString();
                        forecastMarker.setPopupContent(`<strong>Prognose</strong><br>${timeStr}<br>${t}<br><em>${escapeHtml(f.symbol||'')}</em>`);
                        label.textContent = `${timeStr} — ${t}`;
                        const tempDisplay = document.getElementById('tempDisplay');
                        if (tempDisplay) tempDisplay.textContent = t;
                    }

                    slider.addEventListener('input', (e)=> updateFrame(parseInt(e.target.value,10)));
                    playBtn.addEventListener('click', ()=>{
                        if (playTimer) { clearInterval(playTimer); playTimer = null; playBtn.textContent='▶'; }
                        else { playBtn.textContent='⏸'; playTimer = setInterval(()=>{ let next = currentIndex+1; if(next>=frames.length) next=0; slider.value = next; updateFrame(next); }, 1200); }
                    });

                    updateFrame(0);
                } else {
                    const metIconUrl = "https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/<?= $symbol ?>.svg";
                    const metIcon = L.icon({ iconUrl: metIconUrl, iconSize: [56,56], iconAnchor: [28,28] });
                    L.marker([lat, lon], { icon: metIcon }).addTo(map).bindPopup("<strong>MET.no</strong><br><?= $temp_now ?>°");
                }

                if (!plotted) {
                    noCoordsControl = L.control({position:'topright'});
                    noCoordsControl.onAdd = function() {
                        const div = L.DomUtil.create('div', 'p-2 rounded text-xs');
                        div.style.background = 'rgba(2,6,23,0.8)';
                        div.style.color = 'white';
                        div.style.margin = '6px';
                        div.style.padding = '6px 10px';
                        div.style.border = '1px solid rgba(255,255,255,0.05)';
                        div.innerHTML = reportsHaveCoords ? 'Ingen rapporter med koordinater.' : 'Tillat posisjon i skjemaet for å vise lokale markører.';
                        return div;
                    };
                    noCoordsControl.addTo(map);
                }
            })();
        </script>
</body>
</html>

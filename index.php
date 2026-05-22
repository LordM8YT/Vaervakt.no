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
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/vendor/leaflet.markercluster/MarkerCluster.css">
    <link rel="stylesheet" href="assets/vendor/leaflet.markercluster/MarkerCluster.Default.css">
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

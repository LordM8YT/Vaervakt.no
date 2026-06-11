<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function vv_admin_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function vv_admin_is_likely_mobile(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return (bool) preg_match('/android|iphone|ipad|ipod|mobile|opera mini|iemobile/', $ua);
}

function vv_admin_verify_password(string $password): bool
{
    if (ADMIN_PASSWORD_HASH !== '') {
        return password_verify($password, ADMIN_PASSWORD_HASH);
    }

    return ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $password);
}

function vv_admin_render_shell(string $title, string $body, bool $isLogin = false): void
{
    $bodyClass = $isLogin ? 'admin-login-page' : 'admin-page';
    echo '<!doctype html><html lang="nb"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow,noarchive">';
    echo '<title>' . vv_admin_h($title) . ' - Værvakt admin</title>';
    echo '<style>
        :root{color-scheme:dark;--bg:#101316;--panel:#171c22;--panel2:#1f252d;--line:#2d3540;--text:#f4f7fb;--muted:#aab4c2;--accent:#84d6ff;--good:#7ee7b5;--warn:#ffd166}
        *{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#111820,#0d1116);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:inherit}.desktop-block{display:none}.admin-wrap{width:min(1180px,calc(100% - 48px));margin:0 auto;padding:34px 0 56px}
        .topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:26px}.eyebrow{margin:0 0 8px;color:var(--accent);font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
        h1{margin:0;font-size:32px;line-height:1.05;letter-spacing:0}.muted{color:var(--muted)}.grid{display:grid;gap:14px}.stats{grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px}
        .card{background:linear-gradient(180deg,var(--panel),#131820);border:1px solid var(--line);border-radius:8px;padding:18px;box-shadow:0 18px 50px rgba(0,0,0,.24)}
        .stat-label{margin:0 0 10px;color:var(--muted);font-size:12px;font-weight:750;text-transform:uppercase;letter-spacing:.12em}.stat-value{margin:0;font-size:32px;font-weight:850;letter-spacing:0}
        .stat-note{margin:8px 0 0;color:var(--muted);font-size:13px}.layout{grid-template-columns:1.35fr .65fr;align-items:start}
        table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.12em}
        .temp{font-weight:850}.pill{display:inline-flex;align-items:center;min-height:28px;padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:#111820;color:var(--muted);font-size:12px;font-weight:750}
        .toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 13px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font-size:13px;font-weight:800;text-decoration:none;cursor:pointer}
        .button-primary{background:var(--accent);border-color:var(--accent);color:#061016}.button-danger{background:#25181c;border-color:#55303a;color:#ffbfcb}
        .list{display:grid;gap:10px}.list-row{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid rgba(255,255,255,.08)}.list-row:last-child{border-bottom:0}
        .login{display:grid;place-items:center;min-height:100vh;padding:24px}.login-card{width:min(420px,100%)}label{display:block;margin:0 0 7px;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.12em}
        input{width:100%;min-height:46px;margin-bottom:14px;padding:0 13px;border:1px solid var(--line);border-radius:8px;background:#0f141a;color:var(--text);font-size:16px}.error{margin:0 0 14px;color:#ffbeca;font-weight:750}
        .desktop-warning{display:none;min-height:100vh;padding:28px;place-items:center;text-align:center}.desktop-warning .card{max-width:480px}.small{font-size:13px}
        .pager{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}.nowrap{white-space:nowrap}.coord{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--muted)}
        .admin-section{margin-bottom:18px}.status-pill{display:inline-flex;align-items:center;min-height:28px;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:850}.status-pending{background:rgba(255,209,102,.13);color:#ffe4a3;border:1px solid rgba(255,209,102,.24)}.status-approved{background:rgba(126,231,181,.13);color:#baf7d9;border:1px solid rgba(126,231,181,.24)}.status-sent{background:rgba(132,214,255,.13);color:#bfeeff;border:1px solid rgba(132,214,255,.24)}
        .actions{display:flex;flex-wrap:wrap;gap:8px}.inline-form{display:inline}.button-good{background:#163126;border-color:#265946;color:#baf7d9}.copy-box{width:100%;min-height:190px;margin-top:12px;padding:13px;border:1px solid var(--line);border-radius:8px;background:#0f141a;color:var(--text);font:13px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;resize:vertical}.note{margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.45}.subgrid{display:grid;gap:14px;grid-template-columns:1.25fr .75fr}
        @media (max-width:899px){.admin-wrap{display:none}.desktop-warning{display:grid}}
        @media (max-width:1080px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.layout,.subgrid{grid-template-columns:1fr}}
    </style></head><body class="' . vv_admin_h($bodyClass) . '">';
    echo '<div class="desktop-warning"><div class="card"><p class="eyebrow">Desktop only</p><h1>Admin åpnes på PC</h1><p class="muted">Dashboardet er bevisst skjult på mobil og nettbrett.</p></div></div>';
    echo $body;
    echo '</body></html>';
}

if (vv_admin_is_likely_mobile()) {
    vv_admin_render_shell('Desktop kreves', '');
    exit;
}

session_name('vaervakt_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['admin_authed'])) {
    $lockedUntil = (int) ($_SESSION['admin_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $loginError = 'For mange forsøk. Vent litt før du prøver igjen.';
    } elseif (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $loginError = 'Ugyldig innlogging. Last siden på nytt.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ok = hash_equals(ADMIN_USERNAME, $username) && vv_admin_verify_password($password);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_authed'] = true;
            $_SESSION['admin_login_fails'] = 0;
            header('Location: /admin/');
            exit;
        }

        $_SESSION['admin_login_fails'] = (int) ($_SESSION['admin_login_fails'] ?? 0) + 1;
        if ($_SESSION['admin_login_fails'] >= 8) {
            $_SESSION['admin_locked_until'] = time() + 300;
        }
        $loginError = 'Feil brukernavn eller passord.';
    }
}

if (empty($_SESSION['admin_authed'])) {
    $disabled = ADMIN_PASSWORD === '' && ADMIN_PASSWORD_HASH === '';
    $body = '<main class="login"><section class="card login-card">';
    $body .= '<p class="eyebrow">Værvakt admin</p><h1>Logg inn</h1><p class="muted">Kun desktop. Ikke en del av PWA-en.</p>';
    if ($disabled) {
        $body .= '<p class="error">Adminpassord mangler. Sett ADMIN_PASSWORD eller DB_PASS på serveren.</p>';
    } elseif ($loginError !== '') {
        $body .= '<p class="error">' . vv_admin_h($loginError) . '</p>';
    }
    $body .= '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . vv_admin_h($_SESSION['csrf']) . '">';
    $body .= '<label for="username">Brukernavn</label><input id="username" name="username" autocomplete="username" required>';
    $body .= '<label for="password">Passord</label><input id="password" name="password" type="password" required>';
    $body .= '<button class="button button-primary" type="submit"' . ($disabled ? ' disabled' : '') . '>Åpne dashboard</button></form></section></main>';
    vv_admin_render_shell('Logg inn', $body, true);
    exit;
}

require_once __DIR__ . '/../db.php';

function vv_admin_table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

function vv_admin_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function vv_admin_source(PDO $pdo): ?array
{
    if (vv_admin_table_exists($pdo, 'weather_reports')) {
        return ['table' => 'weather_reports', 'cols' => vv_admin_columns($pdo, 'weather_reports'), 'legacy' => false];
    }
    if (vv_admin_table_exists($pdo, 'reports')) {
        return ['table' => 'reports', 'cols' => vv_admin_columns($pdo, 'reports'), 'legacy' => true];
    }
    return null;
}

function vv_admin_select_sql(array $source): string
{
    $cols = $source['cols'];
    $legacy = $source['legacy'];
    $select = [];
    $select[] = in_array('id', $cols, true) ? 'id' : 'NULL AS id';
    $select[] = $legacy ? 'reporter_name AS username' : 'username';
    $select[] = $legacy ? 'conditions AS weather_condition' : 'weather_condition';
    $select[] = 'location';
    $select[] = $legacy ? 'temperature_c AS temperature' : 'temperature';
    $select[] = in_array('latitude', $cols, true) ? 'latitude' : 'NULL AS latitude';
    $select[] = in_array('longitude', $cols, true) ? 'longitude' : 'NULL AS longitude';
    $select[] = 'created_at';
    return implode(', ', $select);
}

function vv_admin_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function vv_admin_ensure_visit_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL,
            path VARCHAR(255) NOT NULL DEFAULT '/',
            referrer VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_site_visits_created_at (created_at),
            KEY idx_site_visits_visitor_created (visitor_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_admin_ensure_bathing_place_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bathing_place_suggestions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_name VARCHAR(120) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            reporter VARCHAR(120) NULL,
            note VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_bathing_place_status_created (status, created_at),
            KEY idx_bathing_place_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_admin_bathing_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Godkjent / klar',
        'sent' => 'Sendt til Yr',
        default => 'Pending',
    };
}

function vv_admin_bathing_status_class(string $status): string
{
    return match ($status) {
        'approved' => 'status-approved',
        'sent' => 'status-sent',
        default => 'status-pending',
    };
}

function vv_admin_yr_export_text(array $rows): string
{
    if ($rows === []) {
        return "Ingen godkjente badeplasser ennå.\n\nGodkjenn forslag først, så genereres listen her.";
    }

    $lines = [
        'Hei Tommy!',
        '',
        'Her er badeplasser fra Værvakt som er kvalitetssikret og klare for å legges inn hos Yr:',
        '',
    ];

    foreach ($rows as $row) {
        $lat = number_format((float) $row['latitude'], 6, '.', '');
        $lon = number_format((float) $row['longitude'], 6, '.', '');
        $lines[] = '- ' . (string) $row['place_name'];
        $lines[] = '  Lat/Lon: ' . $lat . ', ' . $lon;
        $lines[] = '  Kontakt/innsender: ' . ((string) ($row['reporter'] ?? '') !== '' ? (string) $row['reporter'] : 'Ikke oppgitt');
        if ((string) ($row['note'] ?? '') !== '') {
            $lines[] = '  Notat: ' . (string) $row['note'];
        }
        $lines[] = '';
    }

    $lines[] = 'Mvh';
    $lines[] = 'Værvakt.no';
    return implode("\n", $lines);
}

$source = vv_admin_source($pdo);
if (!$source) {
    vv_admin_render_shell('Dashboard', '<main class="admin-wrap"><section class="card"><h1>Ingen rapporttabell funnet</h1><p class="muted">Fant verken weather_reports eller reports.</p></section></main>');
    exit;
}

$table = $source['table'];
$selectSql = vv_admin_select_sql($source);

vv_admin_ensure_bathing_place_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        vv_admin_render_shell('Ugyldig handling', '<main class="admin-wrap"><section class="card"><h1>Ugyldig handling</h1><p class="muted">Last siden på nytt og prøv igjen.</p></section></main>');
        exit;
    }

    $id = max(0, (int) ($_POST['id'] ?? 0));
    $action = (string) ($_POST['admin_action'] ?? '');
    if ($id > 0 && $action === 'approve_bathing_place') {
        $stmt = $pdo->prepare("UPDATE bathing_place_suggestions SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($id > 0 && $action === 'mark_bathing_place_sent') {
        $stmt = $pdo->prepare("UPDATE bathing_place_suggestions SET status = 'sent', sent_at = NOW(), reviewed_at = COALESCE(reviewed_at, NOW()), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($id > 0 && $action === 'delete_bathing_place') {
        $stmt = $pdo->prepare('DELETE FROM bathing_place_suggestions WHERE id = ?');
        $stmt->execute([$id]);
    }

    header('Location: /admin/#bathing-places');
    exit;
}

if (($_GET['export'] ?? '') === 'reports_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vaervakt-rapporter.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'tidspunkt', 'brukernavn', 'sted', 'værtype', 'temperatur', 'latitude', 'longitude']);
    $stmt = $pdo->query("SELECT {$selectSql} FROM {$table} ORDER BY created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$row['id'], $row['created_at'], $row['username'], $row['location'], $row['weather_condition'], $row['temperature'], $row['latitude'], $row['longitude']]);
    }
    exit;
}

vv_admin_ensure_visit_table($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;
$totalReports = vv_admin_count($pdo, "SELECT COUNT(*) FROM {$table}");
$reports24h = vv_admin_count($pdo, "SELECT COUNT(*) FROM {$table} WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
$coordReports = in_array('latitude', $source['cols'], true) && in_array('longitude', $source['cols'], true)
    ? vv_admin_count($pdo, "SELECT COUNT(*) FROM {$table} WHERE latitude IS NOT NULL AND longitude IS NOT NULL")
    : 0;
$visitors24h = vv_admin_count($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
$pageviews24h = vv_admin_count($pdo, "SELECT COUNT(*) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
$visitorsHour = vv_admin_count($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 1 HOUR)");
$latestVisit = $pdo->query('SELECT MAX(created_at) FROM site_visits')->fetchColumn() ?: 'Ingen ennå';

$reportStmt = $pdo->prepare("SELECT {$selectSql} FROM {$table} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$reportStmt->execute();
$reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

$locationRows = $pdo->query("SELECT location, COUNT(*) AS total FROM {$table} WHERE location IS NOT NULL AND location <> '' GROUP BY location ORDER BY total DESC, location ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$conditionCol = $source['legacy'] ? 'conditions' : 'weather_condition';
$conditionRows = $pdo->query("SELECT {$conditionCol} AS weather_condition, COUNT(*) AS total FROM {$table} WHERE {$conditionCol} IS NOT NULL AND {$conditionCol} <> '' GROUP BY {$conditionCol} ORDER BY total DESC, {$conditionCol} ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$visitRows = $pdo->query("SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY path ORDER BY views DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$bathingRows = $pdo->query("SELECT id, place_name, latitude, longitude, reporter, note, status, created_at, reviewed_at, sent_at FROM bathing_place_suggestions ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'sent' THEN 2 ELSE 3 END, created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$approvedBathingRows = $pdo->query("SELECT id, place_name, latitude, longitude, reporter, note FROM bathing_place_suggestions WHERE status = 'approved' ORDER BY place_name ASC, created_at ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$pendingBathingCount = vv_admin_count($pdo, "SELECT COUNT(*) FROM bathing_place_suggestions WHERE status = 'pending'");
$approvedBathingCount = vv_admin_count($pdo, "SELECT COUNT(*) FROM bathing_place_suggestions WHERE status = 'approved'");
$yrExportText = vv_admin_yr_export_text($approvedBathingRows);

$pages = max(1, (int) ceil($totalReports / $perPage));
$prevUrl = '/admin/?page=' . max(1, $page - 1);
$nextUrl = '/admin/?page=' . min($pages, $page + 1);

$body = '<main class="admin-wrap">';
$body .= '<header class="topbar"><div><p class="eyebrow">Værvakt admin</p><h1>Dashboard</h1><p class="muted">Rapporter, trafikk og drift. Data fra tabellen <span class="pill">' . vv_admin_h($table) . '</span></p></div>';
$body .= '<div><a class="button" href="/">Åpne app</a> <a class="button button-danger" href="/admin/?logout=1">Logg ut</a></div></header>';

$body .= '<section class="grid stats">';
$body .= '<article class="card"><p class="stat-label">Unike besøk 24t</p><p class="stat-value">' . vv_admin_h($visitors24h) . '</p><p class="stat-note">' . vv_admin_h($pageviews24h) . ' sidevisninger</p></article>';
$body .= '<article class="card"><p class="stat-label">Siste time</p><p class="stat-value">' . vv_admin_h($visitorsHour) . '</p><p class="stat-note">Unike besøkende</p></article>';
$body .= '<article class="card"><p class="stat-label">Rapporter totalt</p><p class="stat-value">' . vv_admin_h($totalReports) . '</p><p class="stat-note">' . vv_admin_h($reports24h) . ' siste 24 timer</p></article>';
$body .= '<article class="card"><p class="stat-label">Kartpunkter</p><p class="stat-value">' . vv_admin_h($coordReports) . '</p><p class="stat-note">Rapporter med koordinater</p></article>';
$body .= '</section>';

$body .= '<section id="bathing-places" class="card admin-section"><div class="toolbar"><div><p class="eyebrow">Badeplass-bidrag</p><p class="muted small">' . vv_admin_h(count($bathingRows)) . ' forslag vist. ' . vv_admin_h($pendingBathingCount) . ' pending, ' . vv_admin_h($approvedBathingCount) . ' klar til Yr.</p></div><button class="button button-primary" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById(\'yr-list\').value)">Generer Yr-liste</button></div>';
$body .= '<div class="subgrid"><div style="overflow:auto"><table><thead><tr><th>Badeplass</th><th>Koordinater</th><th>Bruker/kontakt</th><th>Status</th><th>Handling</th></tr></thead><tbody>';
foreach ($bathingRows as $row) {
    $status = (string) ($row['status'] ?? 'pending');
    $coord = number_format((float) $row['latitude'], 6, '.', '') . ', ' . number_format((float) $row['longitude'], 6, '.', '');
    $body .= '<tr><td><strong>' . vv_admin_h($row['place_name']) . '</strong>';
    if ((string) ($row['note'] ?? '') !== '') {
        $body .= '<p class="note">' . vv_admin_h($row['note']) . '</p>';
    }
    $body .= '<p class="note">Foreslått: ' . vv_admin_h($row['created_at']) . '</p></td>';
    $body .= '<td class="coord">' . vv_admin_h($coord) . '</td><td>' . vv_admin_h((string) ($row['reporter'] ?? '') !== '' ? $row['reporter'] : 'Ikke oppgitt') . '</td>';
    $body .= '<td><span class="status-pill ' . vv_admin_h(vv_admin_bathing_status_class($status)) . '">' . vv_admin_h(vv_admin_bathing_status_label($status)) . '</span></td><td><div class="actions">';
    if ($status === 'pending') {
        $body .= '<form class="inline-form" method="post"><input type="hidden" name="csrf" value="' . vv_admin_h($_SESSION['csrf']) . '"><input type="hidden" name="id" value="' . vv_admin_h($row['id']) . '"><button class="button button-good" name="admin_action" value="approve_bathing_place" type="submit">Godkjenn / klar</button></form>';
    } elseif ($status === 'approved') {
        $body .= '<form class="inline-form" method="post"><input type="hidden" name="csrf" value="' . vv_admin_h($_SESSION['csrf']) . '"><input type="hidden" name="id" value="' . vv_admin_h($row['id']) . '"><button class="button button-primary" name="admin_action" value="mark_bathing_place_sent" type="submit">Marker sendt</button></form>';
    }
    $body .= '<form class="inline-form" method="post" onsubmit="return confirm(\'Slette dette badeplassforslaget?\')"><input type="hidden" name="csrf" value="' . vv_admin_h($_SESSION['csrf']) . '"><input type="hidden" name="id" value="' . vv_admin_h($row['id']) . '"><button class="button button-danger" name="admin_action" value="delete_bathing_place" type="submit">Avvis / slett</button></form>';
    $body .= '</div></td></tr>';
}
$body .= $bathingRows ? '' : '<tr><td colspan="5" class="muted">Ingen badeplassforslag ennå.</td></tr>';
$body .= '</tbody></table></div><aside><p class="eyebrow">Generer Yr-liste</p><p class="muted small">Listen under tar bare med godkjente forslag. Marker som sendt etter at du har sendt e-posten.</p><textarea id="yr-list" class="copy-box" readonly>' . vv_admin_h($yrExportText) . '</textarea></aside></div></section>';

$body .= '<section class="grid layout"><article class="card"><div class="toolbar"><div><p class="eyebrow">Alle rapporter</p><p class="muted small">Viser ' . vv_admin_h(count($reports)) . ' av ' . vv_admin_h($totalReports) . '</p></div><a class="button button-primary" href="/admin/?export=reports_csv">Last ned CSV</a></div>';
$body .= '<div style="overflow:auto"><table><thead><tr><th>Tid</th><th>Bruker</th><th>Sted</th><th>Vær</th><th>Temp</th><th>Koordinater</th></tr></thead><tbody>';
foreach ($reports as $row) {
    $coord = $row['latitude'] !== null && $row['longitude'] !== null ? number_format((float) $row['latitude'], 4) . ', ' . number_format((float) $row['longitude'], 4) : 'Mangler';
    $body .= '<tr><td class="nowrap">' . vv_admin_h($row['created_at']) . '</td><td>' . vv_admin_h($row['username']) . '</td><td>' . vv_admin_h($row['location']) . '</td><td><span class="pill">' . vv_admin_h($row['weather_condition']) . '</span></td><td class="temp nowrap">' . vv_admin_h(round((float) $row['temperature'], 1)) . '°</td><td class="coord">' . vv_admin_h($coord) . '</td></tr>';
}
$body .= '</tbody></table></div><div class="pager">';
if ($page > 1) $body .= '<a class="button" href="' . vv_admin_h($prevUrl) . '">Forrige</a>';
$body .= '<span class="pill">Side ' . vv_admin_h($page) . ' av ' . vv_admin_h($pages) . '</span>';
if ($page < $pages) $body .= '<a class="button" href="' . vv_admin_h($nextUrl) . '">Neste</a>';
$body .= '</div></article>';

$body .= '<aside class="grid">';
$body .= '<article class="card"><p class="eyebrow">Mest aktive steder</p><div class="list">';
foreach ($locationRows as $row) {
    $body .= '<div class="list-row"><span>' . vv_admin_h($row['location']) . '</span><strong>' . vv_admin_h($row['total']) . '</strong></div>';
}
$body .= $locationRows ? '' : '<p class="muted">Ingen stedsdata ennå.</p>';
$body .= '</div></article>';

$body .= '<article class="card"><p class="eyebrow">Værtyper</p><div class="list">';
foreach ($conditionRows as $row) {
    $body .= '<div class="list-row"><span>' . vv_admin_h($row['weather_condition']) . '</span><strong>' . vv_admin_h($row['total']) . '</strong></div>';
}
$body .= $conditionRows ? '' : '<p class="muted">Ingen værtyper ennå.</p>';
$body .= '</div></article>';

$body .= '<article class="card"><p class="eyebrow">Trafikk 24t</p><p class="muted small">Siste besøk: ' . vv_admin_h($latestVisit) . '</p><div class="list" style="margin-top:12px">';
foreach ($visitRows as $row) {
    $body .= '<div class="list-row"><span>' . vv_admin_h($row['path']) . '</span><strong>' . vv_admin_h($row['visitors']) . ' / ' . vv_admin_h($row['views']) . '</strong></div>';
}
$body .= $visitRows ? '<p class="muted small">Format: unike / visninger.</p>' : '<p class="muted">Besøkstelling starter fra denne versjonen.</p>';
$body .= '</div></article></aside></section></main>';

vv_admin_render_shell('Dashboard', $body);

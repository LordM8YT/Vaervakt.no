<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/api/station-lib.php';

session_name('vaervakt_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_redirect(string $suffix = ''): never
{
    header('Location: /admin/' . $suffix);
    exit;
}

function admin_username(): string
{
    return vv_env('ADMIN_USERNAME', 'admin');
}

function admin_password_configured(): bool
{
    return vv_env('ADMIN_PASSWORD_HASH') !== '' || vv_env('ADMIN_PASSWORD') !== '' || DB_PASS !== '';
}

function admin_verify_login(string $username, string $password): bool
{
    if (!hash_equals(admin_username(), $username)) {
        return false;
    }

    $hash = vv_env('ADMIN_PASSWORD_HASH');
    if ($hash !== '') {
        return password_verify($password, $hash);
    }

    $plain = vv_env('ADMIN_PASSWORD', DB_PASS);
    return $plain !== '' && hash_equals($plain, $password);
}

function admin_is_logged_in(): bool
{
    return (bool) ($_SESSION['admin_ok'] ?? false);
}

function admin_csrf(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['admin_csrf'];
}

function admin_require_csrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(admin_csrf(), $token)) {
        throw new RuntimeException('Ugyldig sikkerhetstoken. Last siden på nytt og prøv igjen.');
    }
}

function admin_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function admin_track_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_hash CHAR(64) NOT NULL,
            path VARCHAR(180) NOT NULL DEFAULT '/',
            viewport VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_visits_created (created_at),
            KEY idx_visits_visitor (visitor_hash, created_at),
            KEY idx_visits_path (path, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!vv_table_has_column($pdo, 'site_visits', 'visitor_hash')) {
        $pdo->exec("ALTER TABLE site_visits ADD COLUMN visitor_hash CHAR(64) NOT NULL DEFAULT '' AFTER id");
    }
    if (!vv_table_has_column($pdo, 'site_visits', 'path')) {
        $pdo->exec("ALTER TABLE site_visits ADD COLUMN path VARCHAR(180) NOT NULL DEFAULT '/' AFTER visitor_hash");
    }
    if (!vv_table_has_column($pdo, 'site_visits', 'viewport')) {
        $pdo->exec("ALTER TABLE site_visits ADD COLUMN viewport VARCHAR(32) NULL AFTER path");
    }
    if (!vv_table_has_column($pdo, 'site_visits', 'created_at')) {
        $pdo->exec('ALTER TABLE site_visits ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
}

function admin_count(PDO $pdo, string $table, string $where = '1=1'): int
{
    if (!admin_table_exists($pdo, $table)) {
        return 0;
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
}

function admin_scalar(PDO $pdo, string $sql, mixed $fallback = 0): mixed
{
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable) {
        return $fallback;
    }
}

function admin_fetch_all(PDO $pdo, string $table, string $sql): array
{
    if (!admin_table_exists($pdo, $table)) {
        return [];
    }
    return $pdo->query($sql)->fetchAll() ?: [];
}

function admin_report_scope(): string
{
    $scope = (string) ($_GET['reports'] ?? 'fresh');
    return in_array($scope, ['fresh', 'old', 'all'], true) ? $scope : 'fresh';
}

function admin_report_scope_where(string $scope): string
{
    return match ($scope) {
        'old' => 'created_at < (NOW() - INTERVAL 7 DAY)',
        'all' => '1=1',
        default => 'created_at >= (NOW() - INTERVAL 7 DAY)',
    };
}

function admin_report_age_label(string $createdAt): string
{
    try {
        $created = new DateTime($createdAt);
        $seconds = max(0, time() - $created->getTimestamp());
    } catch (Throwable) {
        return 'Ukjent';
    }

    if ($seconds < 3600) return max(1, (int) floor($seconds / 60)) . ' min';
    if ($seconds < 86400) return (int) floor($seconds / 3600) . ' t';
    return (int) floor($seconds / 86400) . ' d';
}

function admin_delete_glimpse_file(string $relativePath): void
{
    $root = realpath(APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'glimpses');
    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $realPath = realpath($fullPath);
    if ($root && $realPath && str_starts_with($realPath, $root) && is_file($realPath)) {
        @unlink($realPath);
    }
}

function admin_handle_action(PDO $pdo): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['login'])) {
        return null;
    }

    admin_require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete_report' && $id > 0 && admin_table_exists($pdo, 'weather_reports')) {
        $stmt = $pdo->prepare('DELETE FROM weather_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return 'Rapporten ble slettet.';
    }

    if ($action === 'delete_glimpse' && $id > 0 && admin_table_exists($pdo, 'weather_glimpse_photos')) {
        $stmt = $pdo->prepare('SELECT image_path FROM weather_glimpse_photos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $imagePath = (string) ($stmt->fetchColumn() ?: '');
        if ($imagePath !== '') {
            admin_delete_glimpse_file($imagePath);
        }
        $delete = $pdo->prepare('DELETE FROM weather_glimpse_photos WHERE id = ? LIMIT 1');
        $delete->execute([$id]);
        return 'Bildeglimtet ble slettet.';
    }

    if ($action === 'delete_bath_report' && $id > 0 && admin_table_exists($pdo, 'bath_temperature_reports')) {
        $stmt = $pdo->prepare('DELETE FROM bath_temperature_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return 'Badetemperaturen ble slettet.';
    }

    if ($action === 'create_station') {
        vv_stations_tables($pdo);
        $apiKey = vv_station_api_key();
        $station = vv_station_create($pdo, $_POST, vv_station_status((string) ($_POST['status'] ?? 'approved')), $apiKey);
        return 'Værstasjonen ble opprettet. Station ID: ' . $station['publicId'] . ' API-nøkkel: ' . $apiKey . ' Kopier nøkkelen nå, den vises ikke igjen.';
    }

    if ($action === 'approve_station' && $id > 0 && admin_table_exists($pdo, 'weather_stations')) {
        $stmt = $pdo->prepare('SELECT public_id, api_key_hash FROM weather_stations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $station = $stmt->fetch();
        if (!$station) {
            return 'Fant ikke værstasjonen.';
        }

        if (($station['api_key_hash'] ?? '') === '') {
            $apiKey = vv_station_api_key();
            $hash = vv_station_hash_key((string) $station['public_id'], $apiKey);
            $update = $pdo->prepare("UPDATE weather_stations SET status = 'approved', api_key_hash = ? WHERE id = ? LIMIT 1");
            $update->execute([$hash, $id]);
            return 'Værstasjonen ble godkjent. Station ID: ' . (string) $station['public_id'] . ' API-nøkkel: ' . $apiKey . ' Kopier nøkkelen nå, den vises ikke igjen.';
        }

        $pdo->prepare("UPDATE weather_stations SET status = 'approved' WHERE id = ? LIMIT 1")->execute([$id]);
        return 'Værstasjonen ble godkjent.';
    }

    if ($action === 'disable_station' && $id > 0 && admin_table_exists($pdo, 'weather_stations')) {
        $pdo->prepare("UPDATE weather_stations SET status = 'disabled' WHERE id = ? LIMIT 1")->execute([$id]);
        return 'Værstasjonen ble deaktivert.';
    }

    if ($action === 'regenerate_station_key' && $id > 0 && admin_table_exists($pdo, 'weather_stations')) {
        $stmt = $pdo->prepare('SELECT public_id FROM weather_stations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $publicId = (string) ($stmt->fetchColumn() ?: '');
        if ($publicId === '') {
            return 'Fant ikke værstasjonen.';
        }

        $apiKey = vv_station_api_key();
        $hash = vv_station_hash_key($publicId, $apiKey);
        $pdo->prepare('UPDATE weather_stations SET api_key_hash = ? WHERE id = ? LIMIT 1')->execute([$hash, $id]);
        return 'Ny API-nøkkel for ' . $publicId . ': ' . $apiKey . ' Kopier nøkkelen nå, den vises ikke igjen.';
    }

    if ($action === 'delete_station' && $id > 0 && admin_table_exists($pdo, 'weather_stations')) {
        $stmt = $pdo->prepare('DELETE FROM weather_stations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return 'Værstasjonen og målingene ble slettet.';
    }

    if ($action === 'delete_station_reading' && $id > 0 && admin_table_exists($pdo, 'station_readings')) {
        $stmt = $pdo->prepare('DELETE FROM station_readings WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return 'Stasjonsmålingen ble slettet.';
    }

    return 'Ingen endring ble utført.';
}

function admin_csv_reports(PDO $pdo): never
{
    if (!admin_table_exists($pdo, 'weather_reports')) {
        admin_redirect();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vaervakt-rapporter.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tid', 'Bruker', 'Sted', 'Vær', 'Temperatur', 'Latitude', 'Longitude']);
    $stmt = $pdo->query('SELECT created_at, username, location, weather_condition, temperature, latitude, longitude FROM weather_reports ORDER BY created_at DESC');
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['created_at'],
            $row['username'],
            $row['location'],
            $row['weather_condition'],
            $row['temperature'],
            $row['latitude'],
            $row['longitude'],
        ]);
    }
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!admin_password_configured()) {
        $loginError = 'Sett ADMIN_PASSWORD eller ADMIN_PASSWORD_HASH i .env først.';
    } elseif (admin_verify_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = true;
        admin_csrf();
        admin_redirect();
    } else {
        $loginError = 'Feil brukernavn eller passord.';
    }
}

if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    admin_redirect();
}

$isLoggedIn = admin_is_logged_in();
$pdo = null;
$dbError = '';
$notice = '';
$stats = [];
$reports = [];
$places = [];
$weatherTypes = [];
$visitsByPath = [];
$glimpses = [];
$bathReports = [];
$stations = [];
$stationReadings = [];
$reportScope = admin_report_scope();
$reportWhere = admin_report_scope_where($reportScope);
$reportCount = 0;

if ($isLoggedIn) {
    try {
        $pdo = vv_db();
        admin_track_table($pdo);
        vv_stations_tables($pdo);

        if (($_GET['export'] ?? '') === 'reports') {
            admin_csv_reports($pdo);
        }

        $notice = admin_handle_action($pdo) ?? '';

        $stats = [
            'unique24' => (int) admin_scalar($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR)"),
            'views24' => (int) admin_scalar($pdo, "SELECT COUNT(*) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR)"),
            'lastHour' => (int) admin_scalar($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM site_visits WHERE created_at >= (NOW() - INTERVAL 1 HOUR)"),
            'reportsTotal' => admin_count($pdo, 'weather_reports'),
            'reports24' => admin_count($pdo, 'weather_reports', 'created_at >= (NOW() - INTERVAL 24 HOUR)'),
            'reportsFresh' => admin_count($pdo, 'weather_reports', 'created_at >= (NOW() - INTERVAL 7 DAY)'),
            'reportsOld' => admin_count($pdo, 'weather_reports', 'created_at < (NOW() - INTERVAL 7 DAY)'),
            'mapPoints' => admin_count($pdo, 'weather_reports', 'latitude IS NOT NULL AND longitude IS NOT NULL'),
            'activeGlimpses' => admin_count($pdo, 'weather_glimpse_photos', 'expires_at > NOW()'),
            'bathTotal' => admin_count($pdo, 'bath_temperature_reports'),
            'bathSent' => admin_count($pdo, 'bath_temperature_reports', "yr_status = 'sent'"),
            'bathFailed' => admin_count($pdo, 'bath_temperature_reports', "yr_status = 'failed'"),
            'stationsTotal' => admin_count($pdo, 'weather_stations'),
            'stationsApproved' => admin_count($pdo, 'weather_stations', "status = 'approved'"),
            'stationReadings24' => admin_count($pdo, 'station_readings', 'received_at >= (NOW() - INTERVAL 24 HOUR)'),
        ];

        $reportCount = admin_count($pdo, 'weather_reports', $reportWhere);
        $reports = admin_fetch_all($pdo, 'weather_reports', "SELECT *, created_at >= (NOW() - INTERVAL 7 DAY) AS is_fresh FROM weather_reports WHERE {$reportWhere} ORDER BY created_at DESC LIMIT 200");
        $places = admin_fetch_all($pdo, 'weather_reports', 'SELECT location, COUNT(*) AS total FROM weather_reports GROUP BY location ORDER BY total DESC, location ASC LIMIT 12');
        $weatherTypes = admin_fetch_all($pdo, 'weather_reports', 'SELECT weather_condition, COUNT(*) AS total FROM weather_reports GROUP BY weather_condition ORDER BY total DESC, weather_condition ASC LIMIT 12');
        $visitsByPath = admin_fetch_all($pdo, 'site_visits', "SELECT path, COUNT(DISTINCT visitor_hash) AS unique_visitors, COUNT(*) AS views FROM site_visits WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY path ORDER BY views DESC LIMIT 12");
        $glimpses = admin_fetch_all($pdo, 'weather_glimpse_photos', 'SELECT * FROM weather_glimpse_photos ORDER BY created_at DESC LIMIT 80');
        $bathReports = admin_fetch_all($pdo, 'bath_temperature_reports', 'SELECT * FROM bath_temperature_reports ORDER BY created_at DESC LIMIT 120');
        $stations = admin_fetch_all($pdo, 'weather_stations', "
            SELECT
                s.*,
                (SELECT COUNT(*) FROM station_readings sr WHERE sr.station_id = s.id) AS readings_total,
                (SELECT sr.temperature FROM station_readings sr WHERE sr.station_id = s.id ORDER BY sr.observed_at DESC, sr.id DESC LIMIT 1) AS latest_temperature,
                (SELECT sr.humidity FROM station_readings sr WHERE sr.station_id = s.id ORDER BY sr.observed_at DESC, sr.id DESC LIMIT 1) AS latest_humidity,
                (SELECT sr.pressure FROM station_readings sr WHERE sr.station_id = s.id ORDER BY sr.observed_at DESC, sr.id DESC LIMIT 1) AS latest_pressure,
                (SELECT sr.observed_at FROM station_readings sr WHERE sr.station_id = s.id ORDER BY sr.observed_at DESC, sr.id DESC LIMIT 1) AS latest_observed_at
            FROM weather_stations s
            ORDER BY FIELD(s.status, 'pending', 'approved', 'disabled'), s.updated_at DESC
            LIMIT 200
        ");
        $stationReadings = admin_fetch_all($pdo, 'station_readings', "
            SELECT r.*, s.public_name, s.public_id, s.location_name
            FROM station_readings r
            JOIN weather_stations s ON s.id = r.station_id
            ORDER BY r.observed_at DESC, r.id DESC
            LIMIT 120
        ");
    } catch (Throwable $error) {
        $dbError = $error->getMessage();
    }
}
?><!doctype html>
<html lang="nb">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Værvakt Admin</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #07111f;
      --panel: #101b2d;
      --panel-soft: #142239;
      --border: rgba(148, 163, 184, .24);
      --text: #e2e8f0;
      --muted: #93a4b8;
      --blue: #38bdf8;
      --red: #fb7185;
      --green: #34d399;
      --yellow: #fbbf24;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 12% 0%, rgba(56, 189, 248, .16), transparent 34rem),
        linear-gradient(180deg, #0f172a 0%, var(--bg) 100%);
      color: var(--text);
    }
    a { color: inherit; }
    .mobile-block {
      display: none;
      min-height: 100vh;
      padding: 2rem;
      align-items: center;
      justify-content: center;
      text-align: center;
      color: var(--muted);
    }
    .shell {
      width: min(1480px, calc(100% - 48px));
      margin: 0 auto;
      padding: 24px 0 48px;
    }
    .topbar {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 22px;
    }
    .eyebrow {
      color: #7dd3fc;
      font-size: .72rem;
      font-weight: 900;
      letter-spacing: .14em;
      text-transform: uppercase;
    }
    h1, h2, h3, p { margin: 0; }
    h1 { font-size: clamp(2rem, 3vw, 3.6rem); line-height: 1; }
    h2 { font-size: 1rem; letter-spacing: .08em; text-transform: uppercase; color: #7dd3fc; }
    .muted { color: var(--muted); }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
    .button, button {
      border: 1px solid var(--border);
      border-radius: 999px;
      background: rgba(255, 255, 255, .06);
      color: var(--text);
      padding: 10px 14px;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
    }
    .button.primary, button.primary { background: var(--blue); color: #04111f; border-color: rgba(125, 211, 252, .7); }
    .button.danger, button.danger { background: rgba(251, 113, 133, .14); color: #fecdd3; border-color: rgba(251, 113, 133, .35); }
    .grid { display: grid; gap: 16px; }
    .stats { grid-template-columns: repeat(12, minmax(0, 1fr)); margin-bottom: 16px; }
    .stat {
      grid-column: span 2;
      border: 1px solid var(--border);
      border-radius: 18px;
      background: rgba(15, 23, 42, .72);
      padding: 16px;
      min-height: 112px;
    }
    .stat strong { display: block; margin-top: 8px; font-size: 2.1rem; line-height: 1; }
    .main { grid-template-columns: minmax(0, 1fr) 360px; align-items: start; }
    .card {
      border: 1px solid var(--border);
      border-radius: 20px;
      background: rgba(15, 23, 42, .74);
      box-shadow: 0 24px 60px rgba(0, 0, 0, .18);
      overflow: hidden;
    }
    .card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 16px 18px;
      border-bottom: 1px solid rgba(148, 163, 184, .16);
    }
    .card-body { padding: 16px 18px; }
    table { width: 100%; border-collapse: collapse; }
    th, td {
      padding: 11px 10px;
      border-bottom: 1px solid rgba(148, 163, 184, .14);
      text-align: left;
      vertical-align: top;
      font-size: .9rem;
    }
    th {
      color: #bfdbfe;
      font-size: .72rem;
      letter-spacing: .1em;
      text-transform: uppercase;
    }
    .table-wrap { overflow: auto; max-height: 760px; }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid rgba(148, 163, 184, .2);
      border-radius: 999px;
      padding: 4px 8px;
      color: #cbd5e1;
      background: rgba(255,255,255,.05);
      font-size: .78rem;
      font-weight: 750;
      white-space: nowrap;
    }
    .list { display: grid; gap: 10px; }
    .list-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid rgba(148, 163, 184, .14);
    }
    .notice, .error {
      margin-bottom: 16px;
      border-radius: 16px;
      padding: 12px 14px;
      border: 1px solid rgba(125, 211, 252, .25);
      background: rgba(56, 189, 248, .1);
    }
    .error { border-color: rgba(251, 113, 133, .3); background: rgba(251, 113, 133, .1); color: #fecdd3; }
    .login {
      width: min(440px, calc(100% - 32px));
      margin: 12vh auto 0;
      border: 1px solid var(--border);
      border-radius: 28px;
      background: rgba(15, 23, 42, .82);
      padding: 28px;
    }
    .field { display: grid; gap: 8px; margin-top: 16px; }
    input, select, textarea {
      width: 100%;
      min-height: 46px;
      border: 1px solid rgba(148, 163, 184, .24);
      border-radius: 14px;
      background: rgba(2, 6, 23, .55);
      color: var(--text);
      padding: 0 14px;
      font-size: 16px;
    }
    textarea { min-height: 92px; padding-top: 12px; resize: vertical; }
    select { appearance: none; }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .form-grid .wide { grid-column: 1 / -1; }
    .status-approved { border-color: rgba(52, 211, 153, .34); color: #bbf7d0; background: rgba(52, 211, 153, .12); }
    .status-pending { border-color: rgba(251, 191, 36, .34); color: #fde68a; background: rgba(251, 191, 36, .12); }
    .status-disabled { border-color: rgba(148, 163, 184, .28); color: #cbd5e1; background: rgba(148, 163, 184, .08); }
    .tabs { display: flex; gap: 8px; margin: 10px 0 16px; flex-wrap: wrap; }
    .tabs a { text-decoration: none; }
    .tab-active { background: var(--blue); color: #04111f; }
    .subtabs { display: flex; gap: 8px; flex-wrap: wrap; }
    .status-fresh { border-color: rgba(52, 211, 153, .34); color: #bbf7d0; background: rgba(52, 211, 153, .12); }
    .status-old { border-color: rgba(251, 191, 36, .34); color: #fde68a; background: rgba(251, 191, 36, .12); }
    .thumb {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      object-fit: cover;
      background: var(--panel-soft);
    }
    .inline-form { display: inline; }
    @media (max-width: 900px) {
      .mobile-block { display: flex; }
      .shell, .login { display: none; }
    }
    @media (max-width: 1180px) {
      .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .main { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="mobile-block">
    <div>
      <p class="eyebrow">Værvakt Admin</p>
      <h1>Bruk PC</h1>
      <p style="margin-top:12px">Adminpanelet er bevisst skjult på mobil og nettbrett.</p>
    </div>
  </div>

<?php if (!$isLoggedIn): ?>
  <form class="login" method="post">
    <p class="eyebrow">Værvakt Admin</p>
    <h1>Logg inn</h1>
    <p class="muted" style="margin-top:10px">Bruk `ADMIN_USERNAME` og `ADMIN_PASSWORD` fra `.env`. Hvis passord ikke er satt, brukes databasepassordet som fallback.</p>
    <?php if ($loginError !== ''): ?><div class="error" style="margin-top:16px"><?= h($loginError) ?></div><?php endif; ?>
    <label class="field">
      <span>Brukernavn</span>
      <input name="username" autocomplete="username" value="<?= h(admin_username()) ?>">
    </label>
    <label class="field">
      <span>Passord</span>
      <input name="password" type="password" autocomplete="current-password">
    </label>
    <button class="primary" name="login" value="1" style="width:100%; margin-top:18px">Åpne dashboard</button>
  </form>
<?php else: ?>
  <main class="shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">Værvakt Admin</p>
        <h1>Dashboard</h1>
        <p class="muted" style="margin-top:8px">Rapporter, badetemperaturer, bildeglimt og anonym trafikk.</p>
      </div>
      <div class="actions">
        <a class="button" href="/">Åpne app</a>
        <a class="button primary" href="/admin/?export=reports">Last ned rapporter</a>
        <form method="post" class="inline-form"><button class="danger" name="logout" value="1">Logg ut</button></form>
      </div>
    </header>

    <?php if ($notice !== ''): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($dbError !== ''): ?><div class="error">Kunne ikke lese databasen: <?= h($dbError) ?></div><?php endif; ?>

    <?php if ($dbError === ''): ?>
      <section class="grid stats">
        <div class="stat"><span class="eyebrow">Unike 24t</span><strong><?= h((string) $stats['unique24']) ?></strong><p class="muted"><?= h((string) $stats['views24']) ?> sidevisninger</p></div>
        <div class="stat"><span class="eyebrow">Siste time</span><strong><?= h((string) $stats['lastHour']) ?></strong><p class="muted">Unike besøkende</p></div>
        <div class="stat"><span class="eyebrow">Rapporter</span><strong><?= h((string) $stats['reportsTotal']) ?></strong><p class="muted"><?= h((string) $stats['reports24']) ?> siste 24t · <?= h((string) $stats['reportsFresh']) ?> ferske</p></div>
        <div class="stat"><span class="eyebrow">Kartpunkter</span><strong><?= h((string) $stats['mapPoints']) ?></strong><p class="muted">Rapporter med koordinater</p></div>
        <div class="stat"><span class="eyebrow">Bildeglimt</span><strong><?= h((string) $stats['activeGlimpses']) ?></strong><p class="muted">Aktive nå</p></div>
        <div class="stat"><span class="eyebrow">Badetemp</span><strong><?= h((string) $stats['bathTotal']) ?></strong><p class="muted"><?= h((string) $stats['bathSent']) ?> sendt, <?= h((string) $stats['bathFailed']) ?> feilet</p></div>
        <div class="stat"><span class="eyebrow">Værstasjoner</span><strong><?= h((string) $stats['stationsApproved']) ?></strong><p class="muted"><?= h((string) $stats['stationsTotal']) ?> totalt · <?= h((string) $stats['stationReadings24']) ?> målinger 24t</p></div>
      </section>

      <div class="tabs">
        <a class="button <?= ($_GET['view'] ?? 'reports') === 'reports' ? 'tab-active' : '' ?>" href="/admin/?view=reports">Rapporter</a>
        <a class="button <?= ($_GET['view'] ?? '') === 'bath' ? 'tab-active' : '' ?>" href="/admin/?view=bath">Badetemp</a>
        <a class="button <?= ($_GET['view'] ?? '') === 'glimpses' ? 'tab-active' : '' ?>" href="/admin/?view=glimpses">Bildeglimt</a>
        <a class="button <?= ($_GET['view'] ?? '') === 'stations' ? 'tab-active' : '' ?>" href="/admin/?view=stations">Værstasjoner</a>
        <a class="button <?= ($_GET['view'] ?? '') === 'traffic' ? 'tab-active' : '' ?>" href="/admin/?view=traffic">Trafikk</a>
      </div>

      <?php $view = (string) ($_GET['view'] ?? 'reports'); ?>
      <?php if (!in_array($view, ['reports', 'bath', 'glimpses', 'stations', 'traffic'], true)) $view = 'reports'; ?>
      <section class="grid main">
        <div class="card">
          <?php if ($view === 'glimpses'): ?>
            <div class="card-head"><h2>Bildeglimt</h2><span class="pill"><?= count($glimpses) ?> vist</span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Bilde</th><th>Tid</th><th>Bruker</th><th>Type</th><th>Sted</th><th>Utløper</th><th>Handling</th></tr></thead>
                <tbody>
                <?php foreach ($glimpses as $glimpse): ?>
                  <tr>
                    <td><img class="thumb" src="<?= h((string) $glimpse['image_url']) ?>" alt=""></td>
                    <td><?= h((string) $glimpse['created_at']) ?></td>
                    <td><?= h((string) $glimpse['display_name']) ?></td>
                    <td><strong><?= h((string) $glimpse['title']) ?></strong><br><span class="muted"><?= h((string) $glimpse['snap_type']) ?></span></td>
                    <td><?= h((string) $glimpse['location']) ?></td>
                    <td><?= h((string) $glimpse['expires_at']) ?></td>
                    <td>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                        <input type="hidden" name="id" value="<?= h((string) $glimpse['id']) ?>">
                        <button class="danger" name="action" value="delete_glimpse">Slett</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php elseif ($view === 'bath'): ?>
            <div class="card-head"><h2>Badetemperaturer</h2><span class="pill">Viser <?= count($bathReports) ?> av <?= h((string) $stats['bathTotal']) ?></span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Tid</th><th>Badeplass</th><th>Bruker</th><th>Temp</th><th>Koordinater</th><th>Status</th><th>Yr-svar</th><th>Handling</th></tr></thead>
                <tbody>
                <?php foreach ($bathReports as $bath): ?>
                  <tr>
                    <td><?= h((string) $bath['created_at']) ?></td>
                    <td><strong><?= h((string) $bath['name']) ?></strong><br><span class="muted"><?= ((int) $bath['heated_water'] === 1) ? 'Oppvarmet vann' : 'Naturlig vann' ?></span></td>
                    <td><?= h((string) ($bath['reporter'] ?? '')) ?></td>
                    <td><strong><?= h((string) number_format((float) $bath['temperature'], 1, ',', '')) ?>°</strong></td>
                    <td class="muted"><?= h((string) $bath['latitude'] . ', ' . (string) $bath['longitude']) ?></td>
                    <td><span class="pill"><?= h((string) $bath['yr_status']) ?><?= $bath['yr_http_status'] ? ' · ' . h((string) $bath['yr_http_status']) : '' ?></span></td>
                    <td class="muted"><?= h((string) ($bath['yr_message'] ?? '')) ?></td>
                    <td>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                        <input type="hidden" name="id" value="<?= h((string) $bath['id']) ?>">
                        <button class="danger" name="action" value="delete_bath_report">Slett</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$bathReports): ?>
                  <tr><td colspan="8" class="muted">Ingen badetemperaturer sendt inn ennå.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php elseif ($view === 'stations'): ?>
            <div class="card-head">
              <div>
                <h2>Værstasjoner</h2>
                <p class="muted" style="margin-top:6px">Godkjenn eksterne stasjoner og hent inn automatiske lokale målinger.</p>
              </div>
              <span class="pill"><?= h((string) $stats['stationsApproved']) ?> aktive</span>
            </div>
            <div class="card-body">
              <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                <input type="hidden" name="action" value="create_station">
                <label class="field">
                  <span>Internt navn</span>
                  <input name="name" placeholder="Patrick Netatmo">
                </label>
                <label class="field">
                  <span>Offentlig navn</span>
                  <input name="publicName" placeholder="Tinnheia værstasjon">
                </label>
                <label class="field">
                  <span>Sted</span>
                  <input name="locationName" placeholder="Tinnheia, Kristiansand">
                </label>
                <label class="field">
                  <span>Leverandør</span>
                  <input name="provider" placeholder="Netatmo, Home Assistant, Ecowitt">
                </label>
                <label class="field">
                  <span>Eier/navn</span>
                  <input name="ownerName" placeholder="Valgfritt">
                </label>
                <label class="field">
                  <span>Kontakt</span>
                  <input name="ownerContact" placeholder="Valgfritt">
                </label>
                <label class="field">
                  <span>Breddegrad</span>
                  <input name="lat" inputmode="decimal" placeholder="58.1502">
                </label>
                <label class="field">
                  <span>Lengdegrad</span>
                  <input name="lon" inputmode="decimal" placeholder="7.9527">
                </label>
                <label class="field">
                  <span>Koordinatvisning</span>
                  <select name="coordinatePrecision">
                    <option value="area">Vis område cirka</option>
                    <option value="exact">Vis eksakt</option>
                    <option value="hidden">Skjul koordinater</option>
                  </select>
                </label>
                <label class="field">
                  <span>Status</span>
                  <select name="status">
                    <option value="approved">Godkjent med en gang</option>
                    <option value="pending">Venter</option>
                  </select>
                </label>
                <div class="field wide">
                  <span>Måletyper</span>
                  <div class="actions" style="justify-content:flex-start">
                    <label class="pill"><input type="checkbox" name="capabilities[]" value="temperature" checked style="width:auto; min-height:0"> Temperatur</label>
                    <label class="pill"><input type="checkbox" name="capabilities[]" value="humidity" style="width:auto; min-height:0"> Fuktighet</label>
                    <label class="pill"><input type="checkbox" name="capabilities[]" value="pressure" style="width:auto; min-height:0"> Trykk</label>
                    <label class="pill"><input type="checkbox" name="capabilities[]" value="rain" style="width:auto; min-height:0"> Regn</label>
                    <label class="pill"><input type="checkbox" name="capabilities[]" value="wind" style="width:auto; min-height:0"> Vind</label>
                  </div>
                </div>
                <div class="wide actions" style="justify-content:flex-start">
                  <button class="primary" type="submit">Opprett stasjon og nøkkel</button>
                  <a class="button" href="/docs/weather-stations.md">Åpne API-guide</a>
                </div>
              </form>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Status</th><th>Stasjon</th><th>Station ID</th><th>Siste måling</th><th>Koordinater</th><th>Målinger</th><th>Handling</th></tr></thead>
                <tbody>
                <?php foreach ($stations as $station): ?>
                  <?php
                    $status = vv_station_status((string) $station['status']);
                    $latest = $station['latest_temperature'] !== null ? number_format((float) $station['latest_temperature'], 1, ',', '') . '°' : 'Ingen';
                    if ($station['latest_humidity'] !== null) {
                        $latest .= ' · ' . number_format((float) $station['latest_humidity'], 0, ',', '') . '%';
                    }
                    if ($station['latest_pressure'] !== null) {
                        $latest .= ' · ' . number_format((float) $station['latest_pressure'], 0, ',', '') . ' hPa';
                    }
                  ?>
                  <tr>
                    <td><span class="pill status-<?= h($status) ?>"><?= h($status) ?></span></td>
                    <td><strong><?= h((string) $station['public_name']) ?></strong><br><span class="muted"><?= h((string) $station['location_name']) ?> · <?= h((string) ($station['provider'] ?? '')) ?></span></td>
                    <td><code><?= h((string) $station['public_id']) ?></code></td>
                    <td><strong><?= h($latest) ?></strong><br><span class="muted"><?= h((string) ($station['latest_observed_at'] ?? '')) ?></span></td>
                    <td class="muted"><?= $station['latitude'] !== null ? h((string) $station['latitude'] . ', ' . (string) $station['longitude']) : 'Mangler' ?><br><?= h((string) $station['coordinate_precision']) ?></td>
                    <td><?= h((string) $station['readings_total']) ?></td>
                    <td>
                      <div class="actions" style="justify-content:flex-start">
                        <?php if ($status !== 'approved'): ?>
                          <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                            <input type="hidden" name="id" value="<?= h((string) $station['id']) ?>">
                            <button class="primary" name="action" value="approve_station">Godkjenn</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($status !== 'disabled'): ?>
                          <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                            <input type="hidden" name="id" value="<?= h((string) $station['id']) ?>">
                            <button name="action" value="disable_station">Deaktiver</button>
                          </form>
                        <?php endif; ?>
                        <form method="post" class="inline-form">
                          <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                          <input type="hidden" name="id" value="<?= h((string) $station['id']) ?>">
                          <button name="action" value="regenerate_station_key">Ny nøkkel</button>
                        </form>
                        <form method="post" class="inline-form">
                          <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                          <input type="hidden" name="id" value="<?= h((string) $station['id']) ?>">
                          <button class="danger" name="action" value="delete_station">Slett</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$stations): ?>
                  <tr><td colspan="7" class="muted">Ingen værstasjoner lagt til enda.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div class="card-head" style="border-top:1px solid rgba(148, 163, 184, .16)">
              <h2>Siste stasjonsmålinger</h2>
              <span class="pill"><?= count($stationReadings) ?> vist</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Tid</th><th>Stasjon</th><th>Temp</th><th>Fukt</th><th>Trykk</th><th>Regn</th><th>Vind</th><th>Handling</th></tr></thead>
                <tbody>
                <?php foreach ($stationReadings as $reading): ?>
                  <tr>
                    <td><?= h((string) $reading['observed_at']) ?><br><span class="muted">Mottatt <?= h((string) $reading['received_at']) ?></span></td>
                    <td><strong><?= h((string) $reading['public_name']) ?></strong><br><span class="muted"><?= h((string) $reading['location_name']) ?></span></td>
                    <td><?= $reading['temperature'] !== null ? h(number_format((float) $reading['temperature'], 1, ',', '') . '°') : '—' ?></td>
                    <td><?= $reading['humidity'] !== null ? h(number_format((float) $reading['humidity'], 0, ',', '') . '%') : '—' ?></td>
                    <td><?= $reading['pressure'] !== null ? h(number_format((float) $reading['pressure'], 0, ',', '') . ' hPa') : '—' ?></td>
                    <td><?= $reading['rain_rate'] !== null ? h(number_format((float) $reading['rain_rate'], 1, ',', '') . ' mm/t') : '—' ?></td>
                    <td><?= $reading['wind_speed'] !== null ? h(number_format((float) $reading['wind_speed'], 1, ',', '') . ' m/s') : '—' ?></td>
                    <td>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                        <input type="hidden" name="id" value="<?= h((string) $reading['id']) ?>">
                        <button class="danger" name="action" value="delete_station_reading">Slett</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$stationReadings): ?>
                  <tr><td colspan="8" class="muted">Ingen stasjonsmålinger enda.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php elseif ($view === 'traffic'): ?>
            <div class="card-head"><h2>Trafikk siste 24 timer</h2><span class="pill"><?= h((string) $stats['views24']) ?> visninger</span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Side</th><th>Unike</th><th>Visninger</th></tr></thead>
                <tbody>
                <?php foreach ($visitsByPath as $visit): ?>
                  <tr><td><?= h((string) $visit['path']) ?></td><td><?= h((string) $visit['unique_visitors']) ?></td><td><?= h((string) $visit['views']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="card-head">
              <div>
                <h2>Rapporter</h2>
                <p class="muted" style="margin-top:6px">Public app viser ferske rapporter fra siste 7 dager. Historikk ligger bare her.</p>
              </div>
              <span class="pill">Viser <?= count($reports) ?> av <?= h((string) $reportCount) ?></span>
            </div>
            <div class="card-body" style="padding-bottom:0">
              <div class="subtabs">
                <a class="button <?= $reportScope === 'fresh' ? 'tab-active' : '' ?>" href="/admin/?view=reports&reports=fresh">Ferske <?= h((string) $stats['reportsFresh']) ?></a>
                <a class="button <?= $reportScope === 'old' ? 'tab-active' : '' ?>" href="/admin/?view=reports&reports=old">Eldre <?= h((string) $stats['reportsOld']) ?></a>
                <a class="button <?= $reportScope === 'all' ? 'tab-active' : '' ?>" href="/admin/?view=reports&reports=all">Alle <?= h((string) $stats['reportsTotal']) ?></a>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Status</th><th>Tid</th><th>Bruker</th><th>Sted</th><th>Vær</th><th>Temp</th><th>Koordinater</th><th>Handling</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $report): ?>
                  <?php $isFreshReport = (int) ($report['is_fresh'] ?? 0) === 1; ?>
                  <tr>
                    <td><span class="pill <?= $isFreshReport ? 'status-fresh' : 'status-old' ?>"><?= $isFreshReport ? 'Fersk' : 'Historikk' ?> · <?= h(admin_report_age_label((string) $report['created_at'])) ?></span></td>
                    <td><?= h((string) $report['created_at']) ?></td>
                    <td><?= h((string) $report['username']) ?></td>
                    <td><?= h((string) $report['location']) ?></td>
                    <td><span class="pill"><?= h((string) $report['weather_condition']) ?></span></td>
                    <td><strong><?= h((string) round((float) $report['temperature'])) ?>°</strong></td>
                    <td class="muted"><?= $report['latitude'] !== null ? h((string) $report['latitude'] . ', ' . (string) $report['longitude']) : 'Mangler' ?></td>
                    <td>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
                        <input type="hidden" name="id" value="<?= h((string) $report['id']) ?>">
                        <button class="danger" name="action" value="delete_report">Slett</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                  <tr><td colspan="8" class="muted">Ingen rapporter i dette filteret.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <aside class="grid">
          <div class="card">
            <div class="card-head"><h2>Mest aktive steder</h2></div>
            <div class="card-body list">
              <?php foreach ($places as $place): ?><div class="list-row"><span><?= h((string) $place['location']) ?></span><strong><?= h((string) $place['total']) ?></strong></div><?php endforeach; ?>
              <?php if (!$places): ?><p class="muted">Ingen rapportsteder ennå.</p><?php endif; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><h2>Værtyper</h2></div>
            <div class="card-body list">
              <?php foreach ($weatherTypes as $type): ?><div class="list-row"><span><?= h((string) $type['weather_condition']) ?></span><strong><?= h((string) $type['total']) ?></strong></div><?php endforeach; ?>
              <?php if (!$weatherTypes): ?><p class="muted">Ingen værtyper ennå.</p><?php endif; ?>
            </div>
          </div>
        </aside>
      </section>
    <?php endif; ?>
  </main>
<?php endif; ?>
</body>
</html>

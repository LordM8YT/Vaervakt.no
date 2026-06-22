<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const VV_GLIMPSE_MAX_BYTES = 4194304;
const VV_GLIMPSE_ALLOWED_TTL = [1, 3, 12, 24];

function vv_glimpse_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_hub_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name VARCHAR(80) NOT NULL,
            name_key VARCHAR(96) NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_hub_name (name_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_glimpse_photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            display_name VARCHAR(80) NOT NULL,
            snap_type VARCHAR(32) NOT NULL DEFAULT 'weather',
            title VARCHAR(140) NOT NULL,
            note VARCHAR(500) NOT NULL,
            location VARCHAR(140) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            image_path VARCHAR(255) NOT NULL,
            image_url VARCHAR(255) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_glimpse_active (expires_at, created_at),
            KEY idx_glimpse_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_glimpse_token_hash(string $token): string
{
    return hash('sha256', 'vv2-token|' . trim($token));
}

function vv_glimpse_token_column(PDO $pdo): string
{
    if (vv_table_has_column($pdo, 'weather_hub_users', 'token_hash')) {
        return 'token_hash';
    }
    if (vv_table_has_column($pdo, 'weather_hub_users', 'auth_token_hash')) {
        return 'auth_token_hash';
    }
    $pdo->exec('ALTER TABLE weather_hub_users ADD COLUMN token_hash CHAR(64) NULL AFTER pin_hash');
    return 'token_hash';
}

function vv_glimpse_auth(PDO $pdo, array $input): array
{
    $userId = (int) ($input['userId'] ?? 0);
    $token = trim((string) ($input['token'] ?? ''));
    if ($userId <= 0 || $token === '') {
        vv_error('Logg inn med navn og PIN først.', 401);
    }

    $tokenColumn = vv_glimpse_token_column($pdo);
    $stmt = $pdo->prepare("SELECT id, display_name FROM weather_hub_users WHERE id = ? AND {$tokenColumn} = ? LIMIT 1");
    $stmt->execute([$userId, vv_glimpse_token_hash($token)]);
    $user = $stmt->fetch();
    if (!$user) {
        vv_error('Profiløkten er utløpt. Logg inn på nytt.', 401);
    }

    $pdo->prepare('UPDATE weather_hub_users SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    return $user;
}

function vv_glimpse_upload_root(): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'glimpses';
}

function vv_glimpse_ensure_upload_root(): void
{
    $root = vv_glimpse_upload_root();
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

function vv_glimpse_remove_empty_dirs(string $directory): void
{
    $root = realpath(vv_glimpse_upload_root());
    $current = realpath($directory);
    if (!$root || !$current || !str_starts_with($current, $root) || $current === $root) {
        return;
    }

    @rmdir($current);
    vv_glimpse_remove_empty_dirs(dirname($current));
}

function vv_glimpse_delete_file(string $relativePath): bool
{
    $root = realpath(vv_glimpse_upload_root());
    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $realPath = realpath($fullPath);
    if (!$root || !$realPath || !str_starts_with($realPath, $root)) {
        return false;
    }

    $deleted = is_file($realPath) ? @unlink($realPath) : false;
    vv_glimpse_remove_empty_dirs(dirname($realPath));
    return $deleted;
}

function vv_glimpse_cleanup(PDO $pdo): int
{
    vv_glimpse_ensure_upload_root();
    $stmt = $pdo->query('SELECT id, image_path FROM weather_glimpse_photos WHERE expires_at <= NOW()');
    $expired = $stmt->fetchAll() ?: [];
    if (!$expired) {
        return 0;
    }

    $ids = [];
    foreach ($expired as $row) {
        $ids[] = (int) $row['id'];
        vv_glimpse_delete_file((string) $row['image_path']);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare("DELETE FROM weather_glimpse_photos WHERE id IN ({$placeholders})");
    $delete->execute($ids);
    return count($ids);
}

function vv_glimpse_mime_extension(string $mime): ?string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null,
    };
}

function vv_glimpse_public_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'displayName' => (string) $row['display_name'],
        'snapType' => (string) $row['snap_type'],
        'title' => (string) $row['title'],
        'note' => (string) $row['note'],
        'location' => (string) $row['location'],
        'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
        'lon' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
        'imageUrl' => (string) $row['image_url'],
        'expiresAt' => (string) $row['expires_at'],
        'time' => vv_relative_time((string) $row['created_at']),
    ];
}

function vv_glimpse_get(PDO $pdo): void
{
    $limit = max(1, min(30, (int) ($_GET['limit'] ?? 12)));
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);
    $radiusKm = max(1, min(100, (float) ($_GET['radiusKm'] ?? 35)));
    $terms = vv_location_terms(trim((string) ($_GET['location'] ?? '')));

    $clauses = ['expires_at > NOW()'];
    $params = [];
    $locationClauses = [];

    if ($lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
        $locationClauses[] = '(latitude IS NOT NULL AND longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(latitude)))))) <= ?)';
        array_push($params, $lat, $lon, $lat, $radiusKm);
    }

    foreach ($terms as $term) {
        $locationClauses[] = 'LOWER(location) LIKE ?';
        $params[] = '%' . $term . '%';
    }

    if ($locationClauses) {
        $clauses[] = '(' . implode(' OR ', $locationClauses) . ')';
    }

    $stmt = $pdo->prepare('SELECT * FROM weather_glimpse_photos WHERE ' . implode(' AND ', $clauses) . " ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute($params);
    $photos = array_map('vv_glimpse_public_row', $stmt->fetchAll() ?: []);

    vv_json(['success' => true, 'photos' => $photos]);
}

function vv_glimpse_post(PDO $pdo): void
{
    $user = vv_glimpse_auth($pdo, $_POST);
    $ttlHours = (int) ($_POST['ttlHours'] ?? 3);
    if (!in_array($ttlHours, VV_GLIMPSE_ALLOWED_TTL, true)) {
        vv_error('Velg gyldig varighet: 1, 3, 12 eller 24 timer.');
    }

    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        vv_error('Velg et bilde før du sender.');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > VV_GLIMPSE_MAX_BYTES) {
        vv_error('Bildet må være mindre enn 4 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        vv_error('Kunne ikke lese bildet.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $extension = vv_glimpse_mime_extension($mime);
    if ($extension === null || @getimagesize($tmpName) === false) {
        vv_error('Kun JPG, PNG og WebP-bilder er støttet.');
    }

    vv_glimpse_ensure_upload_root();
    $folder = date('Y') . DIRECTORY_SEPARATOR . date('m');
    $targetDir = vv_glimpse_upload_root() . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create image directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        vv_error('Kunne ikke lagre bildet.', 500);
    }
    @chmod($targetPath, 0644);

    $relativePath = 'uploads/glimpses/' . date('Y') . '/' . date('m') . '/' . $filename;
    $imageUrl = '/' . $relativePath;
    $snapType = vv_limit(trim((string) ($_POST['snapType'] ?? 'weather')), 32);
    $title = vv_limit(trim((string) ($_POST['title'] ?? 'Værglimt')), 140);
    $note = vv_limit(trim((string) ($_POST['note'] ?? '')), 500);
    $location = vv_limit(trim((string) ($_POST['location'] ?? '')), 140);
    $lat = vv_float($_POST['lat'] ?? null);
    $lon = vv_float($_POST['lon'] ?? null);

    if ($title === '' || $location === '') {
        vv_glimpse_delete_file($relativePath);
        vv_error('Mangler tittel eller sted.');
    }

    $expiresAt = (new DateTimeImmutable())->modify('+' . $ttlHours . ' hours')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        INSERT INTO weather_glimpse_photos
            (user_id, display_name, snap_type, title, note, location, latitude, longitude, image_path, image_url, mime_type, file_size, expires_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        (int) $user['id'],
        (string) $user['display_name'],
        $snapType,
        $title,
        $note,
        $location,
        $lat,
        $lon,
        $relativePath,
        $imageUrl,
        $mime,
        (int) $file['size'],
        $expiresAt,
    ]);

    $row = $pdo->prepare('SELECT * FROM weather_glimpse_photos WHERE id = ?');
    $row->execute([(int) $pdo->lastInsertId()]);
    vv_json([
        'success' => true,
        'photo' => vv_glimpse_public_row($row->fetch() ?: []),
        'message' => 'Bildeglimtet er publisert.',
    ]);
}

try {
    $pdo = vv_db();
    vv_glimpse_tables($pdo);
    $deleted = vv_glimpse_cleanup($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['cleanup'])) {
            vv_json(['success' => true, 'deleted' => $deleted]);
        }
        vv_glimpse_get($pdo);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vv_glimpse_post($pdo);
    }

    vv_error('Metoden er ikke støttet.', 405);
} catch (Throwable $error) {
    error_log('glimpses failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere bildeglimt akkurat nå.', 500);
}

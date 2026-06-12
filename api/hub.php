<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

function vv_hub_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function vv_hub_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_hub_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name VARCHAR(80) NOT NULL,
            name_key VARCHAR(96) NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            auth_token_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_hub_user_name_key (name_key),
            KEY idx_hub_user_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_hub_posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            display_name VARCHAR(80) NULL,
            title VARCHAR(140) NOT NULL,
            body TEXT NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'general',
            location VARCHAR(140) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            weather_condition VARCHAR(80) NULL,
            temperature DECIMAL(5,2) NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'user',
            status ENUM('visible', 'hidden', 'deleted') NOT NULL DEFAULT 'visible',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_hub_status_created (status, created_at),
            KEY idx_hub_location_created (location, created_at),
            KEY idx_hub_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!vv_hub_column_exists($pdo, 'weather_hub_posts', 'user_id')) {
        $pdo->exec('ALTER TABLE weather_hub_posts ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_hub_votes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            voter_hash CHAR(64) NOT NULL,
            vote TINYINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_hub_post_voter (post_id, voter_hash),
            KEY idx_hub_vote_post (post_id, vote)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_hub_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function vv_hub_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    return is_array($input) ? $input : $_POST;
}

function vv_hub_substr(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}

function vv_hub_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function vv_hub_float($value): ?float
{
    $float = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    return $float === null ? null : (float) $float;
}

function vv_hub_allowed_category(string $category): string
{
    $category = strtolower(trim($category));
    $allowed = ['general', 'question', 'warning', 'tip', 'photo'];
    return in_array($category, $allowed, true) ? $category : 'general';
}

function vv_hub_name_key(string $displayName): string
{
    $normalized = trim($displayName);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    return vv_hub_substr($normalized, 96);
}

function vv_hub_new_token(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        return hash('sha256', uniqid('vaervakt-hub', true) . mt_rand());
    }
}

function vv_hub_token_hash(string $token): string
{
    return hash('sha256', 'vaervakt-hub-token-v1|' . trim($token));
}

function vv_hub_public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'displayName' => (string) $user['display_name'],
    ];
}

function vv_hub_validate_profile_input(array $input): array
{
    $displayName = trim((string) ($input['displayName'] ?? $input['display_name'] ?? ''));
    $pin = trim((string) ($input['pin'] ?? ''));

    if (vv_hub_strlen($displayName) < 2) {
        vv_hub_json_error('Skriv et navn på minst to tegn.');
    }
    if (vv_hub_strlen($displayName) > 80) {
        vv_hub_json_error('Navnet kan maks være 80 tegn.');
    }
    if (vv_hub_strlen($pin) < 4 || vv_hub_strlen($pin) > 32) {
        vv_hub_json_error('PIN må være mellom 4 og 32 tegn.');
    }

    return [$displayName, $pin, vv_hub_name_key($displayName)];
}

function vv_hub_register(PDO $pdo, array $input): void
{
    [$displayName, $pin, $nameKey] = vv_hub_validate_profile_input($input);

    $existing = $pdo->prepare('SELECT id FROM weather_hub_users WHERE name_key = ? LIMIT 1');
    $existing->execute([$nameKey]);
    if ($existing->fetchColumn()) {
        vv_hub_json_error('Navnet er allerede tatt. Logg inn med PIN eller velg et annet navn.', 409);
    }

    $token = vv_hub_new_token();
    $stmt = $pdo->prepare("
        INSERT INTO weather_hub_users (display_name, name_key, pin_hash, auth_token_hash, last_seen_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        vv_hub_substr($displayName, 80),
        $nameKey,
        password_hash($pin, PASSWORD_DEFAULT),
        vv_hub_token_hash($token),
    ]);

    echo json_encode([
        'success' => true,
        'authToken' => $token,
        'user' => [
            'id' => (int) $pdo->lastInsertId(),
            'displayName' => $displayName,
        ],
        'message' => 'Profilen er klar.',
    ], JSON_UNESCAPED_UNICODE);
}

function vv_hub_login(PDO $pdo, array $input): void
{
    [$displayName, $pin, $nameKey] = vv_hub_validate_profile_input($input);

    $stmt = $pdo->prepare('SELECT id, display_name, pin_hash FROM weather_hub_users WHERE name_key = ? LIMIT 1');
    $stmt->execute([$nameKey]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($pin, (string) $user['pin_hash'])) {
        vv_hub_json_error('Fant ikke profilen eller PIN-koden er feil.', 401);
    }

    $token = vv_hub_new_token();
    $update = $pdo->prepare('UPDATE weather_hub_users SET auth_token_hash = ?, last_seen_at = NOW() WHERE id = ?');
    $update->execute([vv_hub_token_hash($token), (int) $user['id']]);

    echo json_encode([
        'success' => true,
        'authToken' => $token,
        'user' => vv_hub_public_user($user),
        'message' => 'Du er logget inn.',
    ], JSON_UNESCAPED_UNICODE);
}

function vv_hub_auth_user(PDO $pdo, array $input): array
{
    $userId = (int) ($input['userId'] ?? $input['user_id'] ?? 0);
    $token = trim((string) ($input['authToken'] ?? $input['auth_token'] ?? ''));
    if ($userId <= 0 || $token === '') {
        vv_hub_json_error('Logg inn med navn og PIN for å gjøre dette.', 401);
    }

    $stmt = $pdo->prepare('SELECT id, display_name FROM weather_hub_users WHERE id = ? AND auth_token_hash = ? LIMIT 1');
    $stmt->execute([$userId, vv_hub_token_hash($token)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        vv_hub_json_error('Profiløkten er utløpt. Logg inn på nytt.', 401);
    }

    $update = $pdo->prepare('UPDATE weather_hub_users SET last_seen_at = NOW() WHERE id = ?');
    $update->execute([(int) $user['id']]);

    return $user;
}

function vv_hub_relative_time(?string $timestamp): string
{
    if (!$timestamp) return 'Nå nettopp';

    try {
        $created = new DateTime($timestamp);
        $seconds = max(0, time() - $created->getTimestamp());
    } catch (Throwable $error) {
        return 'Nylig';
    }

    if ($seconds < 45) return 'Nå nettopp';
    if ($seconds < 3600) return floor($seconds / 60) . ' min siden';
    if ($seconds < 86400) return floor($seconds / 3600) . ' t siden';
    if ($seconds < 604800) return floor($seconds / 86400) . ' d siden';
    return $created->format('d.m H:i');
}

function vv_hub_location_terms(string $location): array
{
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($location, 'UTF-8') : strtolower($location);
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (strlen($part) < 3 || in_array($part, ['norge', 'norway'], true)) {
            continue;
        }
        $terms[$part] = true;
        if (count($terms) >= 4) {
            break;
        }
    }
    return array_keys($terms);
}

function vv_hub_vote_hash(int $userId): string
{
    return hash('sha256', 'vaervakt-hub-user-vote-v1|' . $userId);
}

function vv_hub_fetch_posts(PDO $pdo): void
{
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $lat = vv_hub_float($_GET['lat'] ?? null);
    $lon = vv_hub_float($_GET['lon'] ?? null);
    $radiusKm = max(1, min(100, (float) ($_GET['radiusKm'] ?? 25)));
    $location = trim((string) ($_GET['location'] ?? $_GET['q'] ?? ''));
    $terms = vv_hub_location_terms($location);
    $sort = strtolower(trim((string) ($_GET['sort'] ?? 'new')));

    $clauses = ["p.status = 'visible'"];
    $params = [];
    $filtered = false;

    $hasCoords = $lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    $locationClauses = [];
    if ($hasCoords) {
        $locationClauses[] = '(p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(p.latitude)) * COS(RADIANS(p.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(p.latitude)))))) <= ?)';
        array_push($params, $lat, $lon, $lat, $radiusKm);
        $filtered = true;
    }

    foreach ($terms as $term) {
        $locationClauses[] = 'LOWER(p.location) LIKE ?';
        $params[] = '%' . $term . '%';
        $filtered = true;
    }

    if ($locationClauses !== []) {
        $clauses[] = '(' . implode(' OR ', $locationClauses) . ')';
    }

    $order = $sort === 'top'
        ? 'score DESC, vote_count DESC, p.created_at DESC'
        : 'p.created_at DESC';

    $sql = "
        SELECT
            p.id,
            p.user_id,
            p.display_name,
            p.title,
            p.body,
            p.category,
            p.location,
            p.latitude,
            p.longitude,
            p.weather_condition,
            p.temperature,
            p.source,
            p.created_at,
            COALESCE(SUM(v.vote), 0) AS score,
            COUNT(v.id) AS vote_count
        FROM weather_hub_posts p
        LEFT JOIN weather_hub_votes v ON v.post_id = p.id
        WHERE " . implode(' AND ', $clauses) . "
        GROUP BY p.id
        ORDER BY {$order}
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $posts = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'displayName' => (string) ($row['display_name'] ?: 'Anonym værvakt'),
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'category' => (string) $row['category'],
            'location' => (string) $row['location'],
            'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'lon' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'weatherCondition' => $row['weather_condition'] !== null ? (string) $row['weather_condition'] : null,
            'temperature' => $row['temperature'] !== null ? (float) $row['temperature'] : null,
            'source' => (string) $row['source'],
            'score' => (int) $row['score'],
            'voteCount' => (int) $row['vote_count'],
            'time' => vv_hub_relative_time($row['created_at'] ?? null),
            'createdAt' => (string) $row['created_at'],
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'filtered' => $filtered,
        'radiusKm' => $filtered ? $radiusKm : null,
        'locationTerms' => $filtered ? $terms : [],
        'posts' => $posts,
    ], JSON_UNESCAPED_UNICODE);
}

function vv_hub_create_post(PDO $pdo, array $input): void
{
    $user = vv_hub_auth_user($pdo, $input);
    $displayName = (string) $user['display_name'];
    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $category = vv_hub_allowed_category((string) ($input['category'] ?? 'general'));
    $location = trim((string) ($input['location'] ?? ''));
    $lat = vv_hub_float($input['lat'] ?? null);
    $lon = vv_hub_float($input['lon'] ?? null);
    $temperature = vv_hub_float($input['temperature'] ?? null);
    $condition = trim((string) ($input['weatherCondition'] ?? $input['weather_condition'] ?? ''));

    if ($title === '' || vv_hub_strlen($title) < 3) {
        vv_hub_json_error('Skriv en kort tittel.');
    }
    if ($body === '' || vv_hub_strlen($body) < 3) {
        vv_hub_json_error('Skriv litt innhold i innlegget.');
    }
    if ($location === '') {
        vv_hub_json_error('Mangler sted for innlegget.');
    }
    if (($lat !== null && ($lat < -90 || $lat > 90)) || ($lon !== null && ($lon < -180 || $lon > 180))) {
        vv_hub_json_error('Koordinatene ser ikke gyldige ut.');
    }

    $title = vv_hub_substr($title, 140);
    $body = vv_hub_substr($body, 1200);
    $location = vv_hub_substr($location, 140);
    $condition = $condition === '' ? null : vv_hub_substr($condition, 80);

    $duplicateStmt = $pdo->prepare("
        SELECT id
        FROM weather_hub_posts
        WHERE title = ?
          AND body = ?
          AND location = ?
          AND created_at >= (NOW() - INTERVAL 20 SECOND)
        LIMIT 1
    ");
    $duplicateStmt->execute([$title, $body, $location]);
    $duplicateId = $duplicateStmt->fetchColumn();
    if ($duplicateId) {
        echo json_encode(['success' => true, 'duplicate' => true, 'postId' => (int) $duplicateId], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO weather_hub_posts (
            user_id,
            display_name,
            title,
            body,
            category,
            location,
            latitude,
            longitude,
            weather_condition,
            temperature,
            source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user')
    ");
    $stmt->execute([(int) $user['id'], $displayName, $title, $body, $category, $location, $lat, $lon, $condition, $temperature]);

    echo json_encode([
        'success' => true,
        'postId' => (int) $pdo->lastInsertId(),
        'message' => 'Innlegget er publisert i værhubben.',
    ], JSON_UNESCAPED_UNICODE);
}

function vv_hub_vote(PDO $pdo, array $input): void
{
    $user = vv_hub_auth_user($pdo, $input);
    $postId = (int) ($input['postId'] ?? $input['post_id'] ?? 0);
    $vote = (int) ($input['vote'] ?? 0);

    if ($postId <= 0) {
        vv_hub_json_error('Mangler innlegg å stemme på.');
    }
    if (!in_array($vote, [-1, 0, 1], true)) {
        vv_hub_json_error('Ugyldig stemme.');
    }

    $existsStmt = $pdo->prepare("SELECT id FROM weather_hub_posts WHERE id = ? AND status = 'visible' LIMIT 1");
    $existsStmt->execute([$postId]);
    if (!$existsStmt->fetchColumn()) {
        vv_hub_json_error('Fant ikke innlegget.', 404);
    }

    $voterHash = vv_hub_vote_hash((int) $user['id']);
    if ($vote === 0) {
        $delete = $pdo->prepare('DELETE FROM weather_hub_votes WHERE post_id = ? AND voter_hash = ?');
        $delete->execute([$postId, $voterHash]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO weather_hub_votes (post_id, voter_hash, vote)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = NOW()
        ");
        $stmt->execute([$postId, $voterHash, $vote]);
    }

    $scoreStmt = $pdo->prepare('SELECT COALESCE(SUM(vote), 0) AS score, COUNT(*) AS vote_count FROM weather_hub_votes WHERE post_id = ?');
    $scoreStmt->execute([$postId]);
    $score = $scoreStmt->fetch(PDO::FETCH_ASSOC) ?: ['score' => 0, 'vote_count' => 0];

    echo json_encode([
        'success' => true,
        'postId' => $postId,
        'myVote' => $vote,
        'score' => (int) $score['score'],
        'voteCount' => (int) $score['vote_count'],
    ], JSON_UNESCAPED_UNICODE);
}

try {
    vv_hub_ensure_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vv_hub_fetch_posts($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        vv_hub_json_error('Metoden er ikke støttet.', 405);
    }

    $input = vv_hub_input();
    $action = strtolower(trim((string) ($input['action'] ?? 'create')));
    if ($action === 'register') {
        vv_hub_register($pdo, $input);
        exit;
    }
    if ($action === 'login') {
        vv_hub_login($pdo, $input);
        exit;
    }
    if ($action === 'vote') {
        vv_hub_vote($pdo, $input);
        exit;
    }

    vv_hub_create_post($pdo, $input);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('hub api failed: ' . $error->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Kunne ikke håndtere værhubben akkurat nå.',
    ], JSON_UNESCAPED_UNICODE);
}

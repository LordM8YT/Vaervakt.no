<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_hub_tables(PDO $pdo): void
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
        CREATE TABLE IF NOT EXISTS weather_hub_posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            display_name VARCHAR(80) NOT NULL,
            title VARCHAR(140) NOT NULL,
            body TEXT NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'general',
            location VARCHAR(140) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            weather_condition VARCHAR(80) NULL,
            temperature DECIMAL(5,2) NULL,
            status ENUM('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_hub_created (status, created_at),
            KEY idx_hub_coords (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_hub_votes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            voter_hash CHAR(64) NOT NULL,
            vote TINYINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_hub_vote (post_id, voter_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_hub_name_key(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return vv_limit($name, 96);
}

function vv_hub_token(): string
{
    return bin2hex(random_bytes(32));
}

function vv_hub_token_hash(string $token): string
{
    return hash('sha256', 'vv2-token|' . trim($token));
}

function vv_hub_auth(PDO $pdo, array $input): array
{
    $userId = (int) ($input['userId'] ?? 0);
    $token = trim((string) ($input['token'] ?? ''));
    if ($userId <= 0 || $token === '') {
        vv_error('Logg inn med navn og PIN først.', 401);
    }

    $stmt = $pdo->prepare('SELECT id, display_name FROM weather_hub_users WHERE id = ? AND token_hash = ? LIMIT 1');
    $stmt->execute([$userId, vv_hub_token_hash($token)]);
    $user = $stmt->fetch();
    if (!$user) {
        vv_error('Profiløkten er utløpt. Logg inn på nytt.', 401);
    }

    $pdo->prepare('UPDATE weather_hub_users SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    return $user;
}

function vv_hub_login_or_register(PDO $pdo, array $input, bool $register): void
{
    $name = trim((string) ($input['displayName'] ?? ''));
    $pin = trim((string) ($input['pin'] ?? ''));
    if (vv_len($name) < 2 || vv_len($name) > 80) {
        vv_error('Navn må være mellom 2 og 80 tegn.');
    }
    if (vv_len($pin) < 4 || vv_len($pin) > 32) {
        vv_error('PIN må være mellom 4 og 32 tegn.');
    }

    $key = vv_hub_name_key($name);
    $stmt = $pdo->prepare('SELECT id, display_name, pin_hash FROM weather_hub_users WHERE name_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $user = $stmt->fetch();

    if ($register) {
        if ($user) {
            vv_error('Navnet er allerede tatt. Logg inn eller velg et annet navn.', 409);
        }
        $token = vv_hub_token();
        $insert = $pdo->prepare('INSERT INTO weather_hub_users (display_name, name_key, pin_hash, token_hash, last_seen_at) VALUES (?, ?, ?, ?, NOW())');
        $insert->execute([vv_limit($name, 80), $key, password_hash($pin, PASSWORD_DEFAULT), vv_hub_token_hash($token)]);
        vv_json([
            'success' => true,
            'user' => ['id' => (int) $pdo->lastInsertId(), 'displayName' => vv_limit($name, 80)],
            'token' => $token,
            'message' => 'Profilen er opprettet.',
        ]);
    }

    if (!$user || !password_verify($pin, (string) $user['pin_hash'])) {
        vv_error('Fant ikke profilen eller PIN er feil.', 401);
    }
    $token = vv_hub_token();
    $pdo->prepare('UPDATE weather_hub_users SET token_hash = ?, last_seen_at = NOW() WHERE id = ?')->execute([vv_hub_token_hash($token), (int) $user['id']]);
    vv_json([
        'success' => true,
        'user' => ['id' => (int) $user['id'], 'displayName' => (string) $user['display_name']],
        'token' => $token,
        'message' => 'Du er logget inn.',
    ]);
}

function vv_hub_get(PDO $pdo): void
{
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $lat = vv_float($_GET['lat'] ?? null);
    $lon = vv_float($_GET['lon'] ?? null);
    $radiusKm = max(1, min(100, (float) ($_GET['radiusKm'] ?? 25)));
    $sort = strtolower((string) ($_GET['sort'] ?? 'new'));
    $terms = vv_location_terms(trim((string) ($_GET['location'] ?? '')));
    $clauses = ["p.status = 'visible'"];
    $params = [];

    $locationClauses = [];
    if ($lat !== null && $lon !== null) {
        $locationClauses[] = '(p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND (6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(p.latitude)) * COS(RADIANS(p.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(p.latitude)))))) <= ?)';
        array_push($params, $lat, $lon, $lat, $radiusKm);
    }
    foreach ($terms as $term) {
        $locationClauses[] = 'LOWER(p.location) LIKE ?';
        $params[] = '%' . $term . '%';
    }
    if ($locationClauses) {
        $clauses[] = '(' . implode(' OR ', $locationClauses) . ')';
    }

    $order = $sort === 'top' ? 'score DESC, p.created_at DESC' : 'p.created_at DESC';
    $stmt = $pdo->prepare("
        SELECT p.*, COALESCE(SUM(v.vote), 0) AS score, COUNT(v.id) AS vote_count
        FROM weather_hub_posts p
        LEFT JOIN weather_hub_votes v ON v.post_id = p.id
        WHERE " . implode(' AND ', $clauses) . "
        GROUP BY p.id
        ORDER BY {$order}
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    $posts = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'displayName' => (string) $row['display_name'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'category' => (string) $row['category'],
            'location' => (string) $row['location'],
            'weatherCondition' => $row['weather_condition'],
            'temperature' => $row['temperature'] !== null ? (float) $row['temperature'] : null,
            'score' => (int) $row['score'],
            'voteCount' => (int) $row['vote_count'],
            'time' => vv_relative_time((string) $row['created_at']),
        ];
    }, $stmt->fetchAll() ?: []);

    vv_json(['success' => true, 'posts' => $posts]);
}

function vv_hub_create(PDO $pdo, array $input): void
{
    $user = vv_hub_auth($pdo, $input);
    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $category = strtolower(trim((string) ($input['category'] ?? 'general')));
    $allowed = ['general', 'question', 'warning', 'tip'];
    $category = in_array($category, $allowed, true) ? $category : 'general';
    $lat = vv_float($input['lat'] ?? null);
    $lon = vv_float($input['lon'] ?? null);
    $temperature = vv_float($input['temperature'] ?? null);
    $condition = trim((string) ($input['weatherCondition'] ?? ''));

    if (vv_len($title) < 3 || vv_len($body) < 3 || $location === '') {
        vv_error('Skriv tittel, tekst og sted.');
    }

    $stmt = $pdo->prepare('INSERT INTO weather_hub_posts (user_id, display_name, title, body, category, location, latitude, longitude, weather_condition, temperature) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int) $user['id'],
        (string) $user['display_name'],
        vv_limit($title, 140),
        vv_limit($body, 1200),
        $category,
        vv_limit($location, 140),
        $lat,
        $lon,
        $condition !== '' ? vv_limit($condition, 80) : null,
        $temperature,
    ]);

    vv_json(['success' => true, 'postId' => (int) $pdo->lastInsertId(), 'message' => 'Innlegget er publisert.']);
}

function vv_hub_vote(PDO $pdo, array $input): void
{
    $user = vv_hub_auth($pdo, $input);
    $postId = (int) ($input['postId'] ?? 0);
    $vote = (int) ($input['vote'] ?? 0);
    if ($postId <= 0 || !in_array($vote, [-1, 0, 1], true)) {
        vv_error('Ugyldig stemme.');
    }
    $hash = hash('sha256', 'vv2-vote|' . (int) $user['id']);
    if ($vote === 0) {
        $pdo->prepare('DELETE FROM weather_hub_votes WHERE post_id = ? AND voter_hash = ?')->execute([$postId, $hash]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO weather_hub_votes (post_id, voter_hash, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = NOW()');
        $stmt->execute([$postId, $hash, $vote]);
    }
    $score = $pdo->prepare('SELECT COALESCE(SUM(vote), 0) AS score, COUNT(*) AS vote_count FROM weather_hub_votes WHERE post_id = ?');
    $score->execute([$postId]);
    $row = $score->fetch() ?: ['score' => 0, 'vote_count' => 0];
    vv_json(['success' => true, 'score' => (int) $row['score'], 'voteCount' => (int) $row['vote_count']]);
}

try {
    $pdo = vv_db();
    vv_hub_tables($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vv_hub_get($pdo);
    }

    $input = vv_request_body();
    $action = strtolower((string) ($input['action'] ?? 'create'));
    if ($action === 'register') vv_hub_login_or_register($pdo, $input, true);
    if ($action === 'login') vv_hub_login_or_register($pdo, $input, false);
    if ($action === 'vote') vv_hub_vote($pdo, $input);
    if ($action === 'create') vv_hub_create($pdo, $input);
    vv_error('Ukjent handling.', 400);
} catch (Throwable $error) {
    error_log('hub failed: ' . $error->getMessage());
    vv_error('Kunne ikke håndtere Værhub akkurat nå.', 500);
}

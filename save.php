<?php
require_once 'db.php';

function vaervakt_respond_error($error, $message, $statusCode = 400)
{
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => 0,
            'error' => $error,
            'message' => $message,
        ]);
        exit;
    }

    header('Location: index.php?error=' . rawurlencode($error));
    exit;
}

function vaervakt_is_valid_timestamp($value)
{
    return is_numeric($value) && (string)(int)$value === (string)$value;
}

function vaervakt_client_identifier()
{
    $parts = [];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $parts[] = $_SERVER['REMOTE_ADDR'];
    }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $parts[] = $_SERVER['HTTP_USER_AGENT'];
    }
    return implode('|', $parts);
}

function vaervakt_rate_limit_file($clientIdentifier)
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vaervakt-rate-' . sha1($clientIdentifier) . '.json';
}

function vaervakt_check_rate_limit($clientIdentifier)
{
    $path = vaervakt_rate_limit_file($clientIdentifier);
    $now = time();
    $entries = [];

    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_int($value) || ctype_digit((string)$value)) {
                    $entries[] = (int)$value;
                }
            }
        }
    }

    $windowSeconds = 15 * 60;
    $burstSeconds = 60;
    $recentEntries = [];
    foreach ($entries as $entry) {
        if ($entry >= ($now - $windowSeconds)) {
            $recentEntries[] = $entry;
        }
    }

    if (!empty($recentEntries)) {
        $lastEntry = max($recentEntries);
        if ($lastEntry >= ($now - 4)) {
            return [false, 'Vent et par sekunder før du sender en ny værrapport.'];
        }
    }

    $burstCount = 0;
    foreach ($recentEntries as $entry) {
        if ($entry >= ($now - $burstSeconds)) {
            $burstCount++;
        }
    }

    if ($burstCount >= 3) {
        return [false, 'Du sender litt raskt akkurat nå. Prøv igjen om et lite minutt.'];
    }

    if (count($recentEntries) >= 12) {
        return [false, 'Det er nådd en midlertidig grense for hvor mange rapporter som kan sendes. Prøv igjen senere.'];
    }

    $recentEntries[] = $now;
    @file_put_contents($path, json_encode($recentEntries), LOCK_EX);

    return [true, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            foreach ($json as $k => $v) {
                if (!isset($_POST[$k])) {
                    $_POST[$k] = $v;
                }
            }
        }
    }

    $queuedReplay = isset($_POST['queued_replay']) && (string)$_POST['queued_replay'] === '1';
    if (!$queuedReplay) {
        $honeypot = trim((string)($_POST['company_website'] ?? ''));
        if ($honeypot !== '') {
            vaervakt_respond_error('spam_blocked', 'Kunne ikke sende rapporten akkurat nå.', 422);
        }

        $formStartedAt = $_POST['form_started_at'] ?? '';
        if (vaervakt_is_valid_timestamp((string)$formStartedAt)) {
            $formAge = time() - (int)$formStartedAt;
            if ($formAge < 1) {
                vaervakt_respond_error('form_too_fast', 'Rapporten kom litt for raskt. Vent et øyeblikk og prøv igjen.', 429);
            }
        }
    }

    $user = trim($_POST['user'] ?? '');
    $weather = trim($_POST['weather_type'] ?? '');
    $loc = trim($_POST['loc'] ?? '');
    $temp = filter_var($_POST['temp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $lat = isset($_POST['lat']) ? filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT) : null;
    $lon = isset($_POST['lon']) ? filter_var($_POST['lon'], FILTER_VALIDATE_FLOAT) : null;

    if (empty($user) || empty($weather) || empty($loc) || $temp === false) {
        vaervakt_respond_error('invalid_input', 'Fyll inn navn, sted, temperatur og værtype før du sender.', 422);
    }

    $rateLimit = vaervakt_check_rate_limit(vaervakt_client_identifier());
    if (!$rateLimit[0]) {
        vaervakt_respond_error('rate_limited', $rateLimit[1], 429);
    }

    $user = substr($user, 0, 50);
    $loc = substr($loc, 0, 100);
    $weather = substr($weather, 0, 50);

    try {
        $table = null;
        $tstmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $tstmt->execute(['weather_reports']);
        if ($tstmt->fetchColumn() > 0) {
            $table = 'weather_reports';
        } else {
            $tstmt->execute(['reports']);
            if ($tstmt->fetchColumn() > 0) {
                $table = 'reports';
            }
        }

        if (!$table) {
            throw new Exception('Ingen støtte for database-tabell funnet (weather_reports eller reports)');
        }

        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
        $colStmt->execute([$table]);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($table === 'weather_reports') {
            $base = ['username', 'weather_condition', 'location', 'temperature'];
            $params = [$user, $weather, $loc, $temp];
            if (in_array('latitude', $cols, true) && $lat !== null && $lat !== false) {
                $base[] = 'latitude';
                $params[] = $lat;
            }
            if (in_array('longitude', $cols, true) && $lon !== null && $lon !== false) {
                $base[] = 'longitude';
                $params[] = $lon;
            }
            $columnsSql = implode(', ', $base) . ', created_at';
            $placeholders = implode(', ', array_fill(0, count($params), '?')) . ', NOW()';
            $sql = "INSERT INTO {$table} ({$columnsSql}) VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
        } else {
            $base = ['reporter_name', 'location', 'temperature_c', 'conditions'];
            $params = [$user, $loc, $temp, $weather];
            if (in_array('latitude', $cols, true) && $lat !== null && $lat !== false) {
                $base[] = 'latitude';
                $params[] = $lat;
            }
            if (in_array('longitude', $cols, true) && $lon !== null && $lon !== false) {
                $base[] = 'longitude';
                $params[] = $lon;
            }
            $columnsSql = implode(', ', $base) . ', created_at';
            $placeholders = implode(', ', array_fill(0, count($params), '?')) . ', NOW()';
            $sql = "INSERT INTO {$table} ({$columnsSql}) VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
        }

        if (!$result) {
            throw new Exception('Database insert failed');
        }

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => 1,
                'report' => [
                    'username' => $user,
                    'weather_condition' => $weather,
                    'location' => $loc,
                    'temperature' => $temp,
                    'latitude' => ($lat !== false ? $lat : null),
                    'longitude' => ($lon !== false ? $lon : null),
                    'created_at' => date('c'),
                ],
            ]);
            exit;
        }

        header('Location: index.php?success=1');
        exit;
    } catch (Exception $e) {
        error_log('Save error: ' . $e->getMessage());
        vaervakt_respond_error('save_failed', 'Kunne ikke lagre værrapporten.', 500);
    }
}

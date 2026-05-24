<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('VAERVAKT_ALLOW_DB_EXCEPTION', true);
require_once __DIR__ . '/../config.php';

$dbConnected = false;
$dbMessage = 'Ikke testet';

try {
    require_once __DIR__ . '/../db.php';
    $dbConnected = isset($pdo) && $pdo instanceof PDO;
    $dbMessage = $dbConnected ? 'OK' : 'Ingen PDO-tilkobling';
} catch (Throwable $error) {
    $dbMessage = $error->getMessage();
}

echo json_encode([
    'success' => true,
    'envFileLoaded' => VAERVAKT_ENV_FIL_LASTET,
    'envLoadedFrom' => VAERVAKT_ENV_LASTET_FRA !== '' ? basename(VAERVAKT_ENV_LASTET_FRA) : '',
    'dbConfigured' => DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '',
    'dbConnected' => $dbConnected,
    'dbMessage' => $dbMessage,
    'vapidPublicConfigured' => VAPID_PUBLIC !== '',
    'vapidPrivateConfigured' => VAPID_PRIVATE !== '',
    'vapidSubjectConfigured' => VAPID_SUBJECT !== '',
    'checkedEnvFiles' => array_map('basename', $config['env_checked_paths'] ?? []),
    'phpVersion' => PHP_VERSION,
], JSON_UNESCAPED_UNICODE);

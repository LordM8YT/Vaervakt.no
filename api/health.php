<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$db = false;
$message = 'OK';
$checkedEnvFiles = [
    APP_ROOT . DIRECTORY_SEPARATOR . '.env',
    dirname(APP_ROOT) . DIRECTORY_SEPARATOR . '.env',
];

try {
    vv_db()->query('SELECT 1');
    $db = true;
} catch (Throwable $error) {
    $message = $error->getMessage();
}

$vapidPrivate = vv_env_first(['VAPID_PRIVATE', 'VAPID_PRIVATE_KEY', 'VAPID_PRIVATEKEY', 'WEB_PUSH_PRIVATE_KEY']);
$vapidSubject = vv_env_first(['VAPID_SUBJECT', 'WEB_PUSH_SUBJECT'], 'mailto:kontakt@vaervakt.no');

vv_json([
    'success' => true,
    'database' => $db,
    'dbConfigured' => DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '',
    'dbHostConfigured' => DB_HOST !== '',
    'dbNameConfigured' => DB_NAME !== '',
    'dbUserConfigured' => DB_USER !== '',
    'vapidPublicConfigured' => VAPID_PUBLIC !== '',
    'vapidPrivateConfigured' => $vapidPrivate !== '',
    'vapidSubjectConfigured' => $vapidSubject !== '',
    'yrBathApiConfigured' => YR_BATH_API_KEY !== '',
    'checkedEnvFiles' => array_map(static fn (string $path): array => [
        'path' => basename($path),
        'readable' => is_readable($path),
    ], $checkedEnvFiles),
    'phpVersion' => PHP_VERSION,
    'message' => $db ? 'Værvakt API er klar.' : 'API kjører, men databasen svarer ikke ennå.',
    'debugMessage' => vv_env('VAERVAKT_DEBUG') === '1' ? $message : null,
]);

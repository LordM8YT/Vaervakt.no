<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config.php';

$payload = [
    'success' => true,
    'vapidPublicKey' => VAPID_PUBLIC,
    'pushReady' => VAPID_PUBLIC !== '',
    'subscriptionEndpoint' => 'api/subscription.php',
    'diagnostics' => [
        'envFileLoaded' => VAERVAKT_ENV_FIL_LASTET,
        'dbConfigured' => DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '',
        'vapidPublicConfigured' => VAPID_PUBLIC !== '',
        'vapidPrivateConfigured' => VAPID_PRIVATE !== '',
    ],
];

if (isset($_GET['vaervakt_debug']) && $_GET['vaervakt_debug'] === '1') {
    $payload['debug'] = [
        'envLoadedFrom' => VAERVAKT_ENV_LASTET_FRA !== '' ? basename(VAERVAKT_ENV_LASTET_FRA) : '',
        'checkedEnvFiles' => array_map('basename', $config['env_checked_paths'] ?? []),
        'dbHostSet' => DB_HOST !== '',
        'dbNameSet' => DB_NAME !== '',
        'dbUserSet' => DB_USER !== '',
        'dbPassSet' => DB_PASS !== '',
        'dbPortSet' => DB_PORT !== '',
        'phpVersion' => PHP_VERSION,
    ];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);

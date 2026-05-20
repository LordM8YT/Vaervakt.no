<?php
require_once __DIR__ . '/config.php';

function get_db_connection() {
    $host = defined('DB_HOST') ? DB_HOST : '';
    $db = defined('DB_NAME') ? DB_NAME : '';
    $user = defined('DB_USER') ? DB_USER : '';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $port = defined('DB_PORT') ? DB_PORT : '';
    $charset = 'utf8mb4';

    if ($host === '' || $db === '' || $user === '') {
        $message = 'Kunne ikke koble til databasen: mangler DB_HOST, DB_NAME eller DB_USER.';
        error_log($message);
        die(defined('VAERVAKT_DEBUG') && VAERVAKT_DEBUG ? $message : 'Tjenesten er midlertidig utilgjengelig.');
    }

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    if ($port !== '') {
        $dsn .= ";port={$port}";
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        die(defined('VAERVAKT_DEBUG') && VAERVAKT_DEBUG ? ('Kunne ikke koble til databasen: ' . $e->getMessage()) : 'Tjenesten er midlertidig utilgjengelig.');
    }
}

$pdo = get_db_connection();

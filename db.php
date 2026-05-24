<?php
require_once __DIR__ . '/config.php';

class VaervaktDatabaseException extends RuntimeException {}

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
        throw new VaervaktDatabaseException($message);
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
        throw new VaervaktDatabaseException('Kunne ikke koble til databasen: ' . $e->getMessage(), 0, $e);
    }
}

try {
    $pdo = get_db_connection();
} catch (VaervaktDatabaseException $e) {
    if (defined('VAERVAKT_ALLOW_DB_EXCEPTION') && VAERVAKT_ALLOW_DB_EXCEPTION) {
        throw $e;
    }

    die(defined('VAERVAKT_DEBUG') && VAERVAKT_DEBUG ? $e->getMessage() : 'Tjenesten er midlertidig utilgjengelig.');
}

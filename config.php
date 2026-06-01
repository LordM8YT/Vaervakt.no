<?php
declare(strict_types=1);

/**
 * Applikasjonskonfigurasjon fra miljø.
 *
 * Dersom vert ikke lar PHP lese `.env`: sett samme variabler i
 * hosting-kontrollpanel (miljøvariabler), eller sørg for at `.env`
 * ligger utenfor webrot med riktige filrettigheter — aldri hardkod
 * passord i sporbar kildekode.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

date_default_timezone_set('Europe/Oslo');

/**
 * Les enkel .env-fil (KEY=VALUE, # kommentarer, tomme linjer ignoreres).
 */
function vaervakt_load_env(string $path): bool
{
    if (!is_readable($path)) {
        return false;
    }

    $raw = file($path, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        return false;
    }

    foreach ($raw as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        // Fjern omsluttende anførselstegn (PHP 7–kompatibel; unngår str_starts_with m.m. fra PHP 8)
        $len = strlen($value);
        if ($len >= 2) {
            $q0 = $value[0];
            $q1 = $value[$len - 1];
            if (($q0 === '"' && $q1 === '"') || ($q0 === "'" && $q1 === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }

    return true;
}

/**
 * Hent miljøvariabel: $_ENV, getenv, med fallback til null.
 */
function vaervakt_env(string $key): ?string
{
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $g = getenv($key);
    if ($g !== false && $g !== '') {
        return $g;
    }
    return null;
}

function vaervakt_env_first(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = vaervakt_env($key);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

function vaervakt_env_paths(): array
{
    $paths = [];
    $custom = vaervakt_env('VAERVAKT_ENV_PATH');
    if ($custom !== null) {
        $paths[] = $custom;
    }

    $paths[] = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
    $paths[] = dirname(APP_ROOT) . DIRECTORY_SEPARATOR . '.env';
    $paths[] = APP_ROOT . DIRECTORY_SEPARATOR . '.env.local';
    $paths[] = dirname(APP_ROOT) . DIRECTORY_SEPARATOR . '.env.local';

    return array_values(array_unique($paths));
}

function vaervakt_parse_database_url(?string $url): array
{
    if ($url === null) {
        return [];
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return [];
    }

    $path = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';

    return [
        'host' => isset($parts['host']) ? (string) $parts['host'] : '',
        'name' => $path,
        'user' => isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
        'pass' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
        'port' => isset($parts['port']) ? (string) $parts['port'] : '',
    ];
}

$envLoaded = false;
$envLoadedPath = '';
$envCheckedPaths = vaervakt_env_paths();
foreach ($envCheckedPaths as $envPath) {
    if (vaervakt_load_env($envPath)) {
        $envLoaded = true;
        $envLoadedPath = $envPath;
        break;
    }
}

$databaseUrl = vaervakt_parse_database_url(vaervakt_env_first(['DATABASE_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']));

// Database (aldri hardkodet passord her — kun fra miljø)
define('DB_HOST', vaervakt_env_first(['DB_HOST', 'MYSQL_HOST', 'MYSQLHOST', 'DATABASE_HOST', 'SQL_HOST']) ?? ($databaseUrl['host'] ?? ''));
define('DB_NAME', vaervakt_env_first(['DB_NAME', 'MYSQL_DATABASE', 'MYSQL_DB', 'MYSQL_DB_NAME', 'DATABASE_NAME', 'SQL_DATABASE']) ?? ($databaseUrl['name'] ?? ''));
define('DB_USER', vaervakt_env_first(['DB_USER', 'MYSQL_USER', 'DATABASE_USER', 'SQL_USER']) ?? ($databaseUrl['user'] ?? ''));
define('DB_PASS', vaervakt_env_first(['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_PASS', 'DATABASE_PASSWORD', 'SQL_PASSWORD', 'SQL_PASS']) ?? ($databaseUrl['pass'] ?? ''));
/** Tom streng = ikke satt (standard MySQL-port brukes da). */
define('DB_PORT', vaervakt_env_first(['DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT', 'SQL_PORT']) ?? ($databaseUrl['port'] ?? ''));

/** Når satt til «1» i .env: tillat `?vaervakt_debug=1` (kun ikke-sensitive data). */
define('VAERVAKT_DEBUG', vaervakt_env('VAERVAKT_DEBUG') === '1');
/** Om .env-fil ble lest fra disk (miljøvariabler kan fortsatt være satt av vert). */
define('VAERVAKT_ENV_FIL_LASTET', $envLoaded);
define('VAERVAKT_ENV_LASTET_FRA', $envLoadedPath);

$config = [
    'db' => [
        'host' => DB_HOST,
        'name' => DB_NAME,
        'user' => DB_USER,
        'pass' => DB_PASS,
        'port' => DB_PORT,
    ],
    'env_file_loaded' => $envLoaded,
    'env_file_path' => $envLoadedPath,
    'env_checked_paths' => $envCheckedPaths,
];

/** VAPID keys for Web Push (optional) — støtter både *_KEY og korte navn */
define('VAPID_PUBLIC', vaervakt_env_first(['VAPID_PUBLIC', 'VAPID_PUBLIC_KEY', 'VAPID_PUBLICKEY', 'PUBLIC_VAPID_KEY', 'WEB_PUSH_PUBLIC_KEY']) ?? '');
define('VAPID_PRIVATE', vaervakt_env_first(['VAPID_PRIVATE', 'VAPID_PRIVATE_KEY', 'VAPID_PRIVATEKEY', 'PRIVATE_VAPID_KEY', 'WEB_PUSH_PRIVATE_KEY']) ?? '');
define('VAPID_SUBJECT', vaervakt_env_first(['VAPID_SUBJECT', 'VAPID_EMAIL', 'WEB_PUSH_SUBJECT']) ?? 'mailto:patrick@vaarvakt.no');
$config['vapid_public'] = VAPID_PUBLIC;
$config['vapid_private'] = VAPID_PRIVATE;
$config['vapid_subject'] = VAPID_SUBJECT;

/** Valgfri støtte-lenke, f.eks. Vipps/Ko-fi/Stripe Checkout */
define('SUPPORT_URL', vaervakt_env('SUPPORT_URL') ?? 'https://betal.vipps.no/opy01u');
define('SUPPORT_LABEL', vaervakt_env('SUPPORT_LABEL') ?? 'Støtt med Vipps');
$config['support_url'] = SUPPORT_URL;
$config['support_label'] = SUPPORT_LABEL;

/** Admin-dashboard. Hvis ADMIN_PASSWORD mangler brukes DB_PASS som serverlokal fallback. */
define('ADMIN_USERNAME', vaervakt_env('ADMIN_USERNAME') ?? 'admin');
define('ADMIN_PASSWORD', vaervakt_env('ADMIN_PASSWORD') ?? DB_PASS);
define('ADMIN_PASSWORD_HASH', vaervakt_env('ADMIN_PASSWORD_HASH') ?? '');
$config['admin_username'] = ADMIN_USERNAME;

unset($envPath);

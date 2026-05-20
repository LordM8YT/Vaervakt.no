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

$envPath = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
$envLoaded = vaervakt_load_env($envPath);

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

// Database (aldri hardkodet passord her — kun fra miljø)
define('DB_HOST', vaervakt_env('DB_HOST') ?? '');
define('DB_NAME', vaervakt_env('DB_NAME') ?? '');
define('DB_USER', vaervakt_env('DB_USER') ?? '');
define('DB_PASS', vaervakt_env('DB_PASS') ?? '');
/** Tom streng = ikke satt (standard MySQL-port brukes da). */
define('DB_PORT', vaervakt_env('DB_PORT') ?? '');

/** Når satt til «1» i .env: tillat `?vaervakt_debug=1` (kun ikke-sensitive data). */
define('VAERVAKT_DEBUG', vaervakt_env('VAERVAKT_DEBUG') === '1');
/** Om .env-fil ble lest fra disk (miljøvariabler kan fortsatt være satt av vert). */
define('VAERVAKT_ENV_FIL_LASTET', $envLoaded);

$config = [
    'db' => [
        'host' => DB_HOST,
        'name' => DB_NAME,
        'user' => DB_USER,
        'pass' => DB_PASS,
        'port' => DB_PORT,
    ],
    'env_file_loaded' => $envLoaded,
];

/** VAPID keys for Web Push (optional) — støtter både *_KEY og korte navn */
define('VAPID_PUBLIC', vaervakt_env('VAPID_PUBLIC') ?? vaervakt_env('VAPID_PUBLIC_KEY') ?? '');
define('VAPID_PRIVATE', vaervakt_env('VAPID_PRIVATE') ?? vaervakt_env('VAPID_PRIVATE_KEY') ?? '');
define('VAPID_SUBJECT', vaervakt_env('VAPID_SUBJECT') ?? 'mailto:patrick@vaarvakt.no');
$config['vapid_public'] = VAPID_PUBLIC;
$config['vapid_private'] = VAPID_PRIVATE;
$config['vapid_subject'] = VAPID_SUBJECT;

/** Valgfri støtte-lenke, f.eks. Vipps/Ko-fi/Stripe Checkout */
define('SUPPORT_URL', vaervakt_env('SUPPORT_URL') ?? '');
define('SUPPORT_LABEL', vaervakt_env('SUPPORT_LABEL') ?? 'Støtt med Vipps');
$config['support_url'] = SUPPORT_URL;
$config['support_label'] = SUPPORT_LABEL;

unset($envPath);

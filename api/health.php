<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$db = false;
$message = 'OK';

try {
    vv_db()->query('SELECT 1');
    $db = true;
} catch (Throwable $error) {
    $message = $error->getMessage();
}

vv_json([
    'success' => true,
    'database' => $db,
    'message' => $db ? 'Værvakt API er klar.' : 'API kjører, men databasen svarer ikke ennå.',
    'debugMessage' => vv_env('VAERVAKT_DEBUG') === '1' ? $message : null,
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        vv_error('Metoden er ikke støttet.', 405);
    }

    $pdo = vv_db();

    // Individuell besøksmåling er avviklet. Første kall etter utrulling
    // sletter også tidligere pseudonyme besøksrader.
    $table = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'site_visits'"
    );
    $table->execute();
    if ((int) $table->fetchColumn() > 0) {
        $pdo->exec('DELETE FROM site_visits');
    }

    vv_json([
        'success' => true,
        'tracking' => false,
        'message' => 'Individuell besøksmåling er deaktivert.',
    ]);
} catch (Throwable $error) {
    error_log('privacy cleanup failed: ' . $error->getMessage());
    vv_error('Kunne ikke fullføre personvernoppryddingen akkurat nå.', 500);
}

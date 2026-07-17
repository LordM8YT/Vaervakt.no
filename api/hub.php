<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_hub_clear_table(PDO $pdo, string $tableName): void
{
    $table = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $table->execute([$tableName]);
    if ((int) $table->fetchColumn() > 0) {
        $pdo->exec("DELETE FROM `{$tableName}`");
    }
}

try {
    $pdo = vv_db();

    // Værglimt/hub er avviklet. Det finnes ikke lenger et formål for å
    // oppbevare profiler, innlegg eller stemmeidentifikatorer.
    vv_hub_clear_table($pdo, 'weather_hub_votes');
    vv_hub_clear_table($pdo, 'weather_hub_posts');
    vv_hub_clear_table($pdo, 'weather_hub_users');

    if (isset($_GET['cleanup'])) {
        vv_json([
            'success' => true,
            'removed' => true,
        ]);
    }

    vv_json([
        'success' => false,
        'removed' => true,
        'message' => 'Værglimt er fjernet fra Værvakt.',
    ], 410);
} catch (Throwable $error) {
    error_log('hub privacy cleanup failed: ' . $error->getMessage());
    vv_error('Kunne ikke fullføre oppryddingen akkurat nå.', 500);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_glimpses_remove_uploads(): int
{
    $root = APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'glimpses';
    if (!is_dir($root)) {
        return 0;
    }

    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
            continue;
        }
        if (@unlink($item->getPathname())) {
            $deleted++;
        }
    }

    @rmdir($root);
    return $deleted;
}

try {
    $pdo = vv_db();
    $deletedFiles = vv_glimpses_remove_uploads();
    $table = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'weather_glimpse_photos'"
    );
    $table->execute();
    if ((int) $table->fetchColumn() > 0) {
        $pdo->exec('DELETE FROM weather_glimpse_photos');
    }

    if (isset($_GET['cleanup'])) {
        vv_json([
            'success' => true,
            'removed' => true,
            'deletedFiles' => $deletedFiles,
        ]);
    }

    vv_json([
        'success' => false,
        'removed' => true,
        'message' => 'Værglimt er fjernet fra Værvakt.',
    ], 410);
} catch (Throwable $error) {
    error_log('glimpse privacy cleanup failed: ' . $error->getMessage());
    vv_error('Kunne ikke fullføre bildeoppryddingen akkurat nå.', 500);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

vv_json([
    'success' => true,
    'support' => [
        'url' => SUPPORT_URL,
        'label' => SUPPORT_LABEL,
    ],
    'pwa' => [
        'vapidPublicKey' => VAPID_PUBLIC,
    ],
]);

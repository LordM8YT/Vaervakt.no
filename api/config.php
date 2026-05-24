<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config.php';

echo json_encode([
    'success' => true,
    'vapidPublicKey' => VAPID_PUBLIC,
    'pushReady' => VAPID_PUBLIC !== '',
    'subscriptionEndpoint' => 'api/subscription.php',
], JSON_UNESCAPED_UNICODE);

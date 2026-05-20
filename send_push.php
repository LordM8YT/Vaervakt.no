#!/usr/bin/env php
<?php
// CLI script to send a Web Push to all subscriptions in `subscriptions` table.
// Usage: php send_push.php --title "Tittel" --body "Melding" --url "/"

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "Please run `composer install` first (requires minishlink/web-push)\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$options = getopt('', ['title::', 'body::', 'url::', 'subject::']);
$title = $options['title'] ?? 'Værvakt: Test varsel';
$body = $options['body'] ?? 'Dette er en testmelding fra Værvakt.';
$url = $options['url'] ?? '/';
$subject = $options['subject'] ?? (defined('VAPID_SUBJECT') && VAPID_SUBJECT ? VAPID_SUBJECT : 'mailto:patrick@vaarvakt.no');

$public = defined('VAPID_PUBLIC') && VAPID_PUBLIC ? VAPID_PUBLIC : getenv('VAPID_PUBLIC');
$private = defined('VAPID_PRIVATE') && VAPID_PRIVATE ? VAPID_PRIVATE : getenv('VAPID_PRIVATE');

if (!$public || !$private) {
    fwrite(STDERR, "VAPID_PUBLIC/VAPID_PRIVATE eller VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY ikke satt. Legg dem i .env eller miljøet.\n");
    exit(2);
}

$auth = [
    'VAPID' => [
        'subject' => $subject,
        'publicKey' => $public,
        'privateKey' => $private,
    ],
];

$webPush = new WebPush($auth);

try {
    $stmt = $pdo->query("SELECT id, endpoint, p256dh, auth FROM subscriptions");
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "Kunne ikke lese subscriptions: " . $e->getMessage() . "\n");
    exit(3);
}

$payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
$queued = 0;

foreach ($subs as $row) {
    if (empty($row['endpoint'])) continue;
    $subscription = Subscription::create([
        'endpoint' => $row['endpoint'],
        'keys' => [
            'p256dh' => $row['p256dh'] ?? '',
            'auth' => $row['auth'] ?? ''
        ]
    ]);
    try {
        $webPush->queueNotification($subscription, $payload);
        $queued++;
    } catch (Exception $e) {
        fwrite(STDERR, "Queue error for subscription id {$row['id']}: {$e->getMessage()}\n");
    }
}

if ($queued === 0) {
    fwrite(STDOUT, "Ingen aktive abonnement funnet.\n");
    exit(0);
}

$success = $fail = 0;

foreach ($webPush->flush() as $report) {
    $endpoint = (string)$report->getRequest()->getUri();
    if ($report->isSuccess()) {
        $success++;
        fwrite(STDOUT, "[OK] $endpoint\n");
    } else {
        $fail++;
        fwrite(STDERR, "[FAIL] $endpoint: " . $report->getReason() . "\n");
        $res = $report->getResponse();
        if ($res && method_exists($res, 'getStatusCode')) {
            $status = $res->getStatusCode();
            if (in_array($status, [404, 410])) {
                // fjern subscription for dette endepunktet
                $del = $pdo->prepare("DELETE FROM subscriptions WHERE endpoint = ?");
                $del->execute([$endpoint]);
                fwrite(STDOUT, "Slettet abonnement for $endpoint (HTTP $status)\n");
            }
        }
    }
}

fwrite(STDOUT, "Ferdig. Success: $success, Fail: $fail\n");

exit(0);

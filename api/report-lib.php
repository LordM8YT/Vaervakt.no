<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vv_reports_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            weather_condition VARCHAR(80) NOT NULL,
            location VARCHAR(140) NOT NULL,
            temperature DECIMAL(5,2) NOT NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            moderation_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            moderation_reason VARCHAR(240) NULL,
            moderated_at DATETIME NULL,
            flag_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_reports_created (created_at),
            KEY idx_reports_location (location),
            KEY idx_reports_coords (latitude, longitude),
            KEY idx_reports_moderation (moderation_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!vv_table_has_column($pdo, 'weather_reports', 'moderation_status')) {
        $pdo->exec("ALTER TABLE weather_reports ADD COLUMN moderation_status VARCHAR(20) NOT NULL DEFAULT 'visible' AFTER longitude, ADD KEY idx_reports_moderation (moderation_status, created_at)");
    }
    if (!vv_table_has_column($pdo, 'weather_reports', 'moderation_reason')) {
        $pdo->exec('ALTER TABLE weather_reports ADD COLUMN moderation_reason VARCHAR(240) NULL AFTER moderation_status');
    }
    if (!vv_table_has_column($pdo, 'weather_reports', 'moderated_at')) {
        $pdo->exec('ALTER TABLE weather_reports ADD COLUMN moderated_at DATETIME NULL AFTER moderation_reason');
    }
    if (!vv_table_has_column($pdo, 'weather_reports', 'flag_count')) {
        $pdo->exec('ALTER TABLE weather_reports ADD COLUMN flag_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER moderated_at');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_report_rate_limits (
            client_hash CHAR(64) NOT NULL,
            window_started_at DATETIME NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (client_hash),
            KEY idx_report_rate_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_report_flags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            client_hash CHAR(64) NOT NULL,
            reason VARCHAR(40) NOT NULL,
            details VARCHAR(240) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_report_flag_client (report_id, client_hash),
            KEY idx_report_flags_created (created_at),
            KEY idx_report_flags_client (client_hash, created_at),
            CONSTRAINT fk_report_flags_report
                FOREIGN KEY (report_id) REFERENCES weather_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function vv_reports_has_control_characters(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
}

function vv_reports_hash_secret(): string
{
    $secret = vv_env_first(
        ['VISIT_HASH_SECRET', 'ADMIN_PASSWORD_HASH', 'VAPID_PRIVATE_KEY'],
        DB_PASS
    );

    return $secret !== '' ? $secret : DB_NAME . '|vaervakt-report-secret';
}

function vv_reports_client_fingerprint(): string
{
    $ip = trim((string) (
        $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown'
    ));
    $agent = vv_limit((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 180);
    return $ip . '|' . $agent;
}

function vv_reports_rate_client_hash(): string
{
    return hash_hmac(
        'sha256',
        vv_reports_client_fingerprint() . '|' . gmdate('Y-m-d-H') . '|report-rate',
        vv_reports_hash_secret()
    );
}

function vv_reports_flag_client_hash(): string
{
    return hash_hmac(
        'sha256',
        vv_reports_client_fingerprint() . '|report-flag',
        vv_reports_hash_secret()
    );
}

function vv_reports_enforce_rate_limit(PDO $pdo): void
{
    $clientHash = vv_reports_rate_client_hash();
    $limit = max(1, min(30, (int) vv_env('REPORT_RATE_LIMIT', '6')));
    $windowMinutes = max(1, min(60, (int) vv_env('REPORT_RATE_WINDOW_MINUTES', '10')));

    $stmt = $pdo->prepare("
        INSERT INTO weather_report_rate_limits
            (client_hash, window_started_at, hits, expires_at)
        VALUES (?, NOW(), 1, DATE_ADD(NOW(), INTERVAL 60 MINUTE))
        ON DUPLICATE KEY UPDATE
            hits = IF(
                window_started_at < (NOW() - INTERVAL {$windowMinutes} MINUTE),
                1,
                hits + 1
            ),
            window_started_at = IF(
                window_started_at < (NOW() - INTERVAL {$windowMinutes} MINUTE),
                NOW(),
                window_started_at
            ),
            expires_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
    ");
    $stmt->execute([$clientHash]);

    $check = $pdo->prepare(
        'SELECT hits FROM weather_report_rate_limits WHERE client_hash = ? LIMIT 1'
    );
    $check->execute([$clientHash]);
    if ((int) $check->fetchColumn() > $limit) {
        header('Retry-After: ' . ($windowMinutes * 60));
        vv_error("Du har sendt mange rapporter på kort tid. Prøv igjen om {$windowMinutes} minutter.", 429);
    }
}

function vv_reports_flag_reasons(): array
{
    return [
        'inaccurate' => 'Feil værdata',
        'spam' => 'Spam eller reklame',
        'abusive' => 'Upassende eller støtende',
        'privacy' => 'Personopplysninger',
        'other' => 'Annet',
    ];
}

function vv_reports_enforce_flag_rate_limit(PDO $pdo, string $clientHash): void
{
    $limit = max(1, min(30, (int) vv_env('REPORT_FLAG_RATE_LIMIT', '10')));
    $windowMinutes = max(5, min(1440, (int) vv_env('REPORT_FLAG_RATE_WINDOW_MINUTES', '60')));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM weather_report_flags WHERE client_hash = ? AND created_at >= (NOW() - INTERVAL {$windowMinutes} MINUTE)");
    $stmt->execute([$clientHash]);

    if ((int) $stmt->fetchColumn() >= $limit) {
        header('Retry-After: ' . ($windowMinutes * 60));
        vv_error('Du har rapportert mange innlegg på kort tid. Prøv igjen senere.', 429);
    }
}

function vv_reports_flag(PDO $pdo, array $input): array
{
    $reportId = filter_var($input['reportId'] ?? null, FILTER_VALIDATE_INT);
    $reason = is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : '';
    $details = is_string($input['details'] ?? null) ? trim((string) $input['details']) : '';

    if (!$reportId || $reportId < 1) {
        vv_error('Rapporten mangler eller er ugyldig.');
    }
    if (!array_key_exists($reason, vv_reports_flag_reasons())) {
        vv_error('Velg en gyldig årsak.');
    }
    if (vv_len($details) > 240 || vv_reports_has_control_characters($details)) {
        vv_error('Kommentaren er for lang eller inneholder ugyldige tegn.');
    }

    $clientHash = vv_reports_flag_client_hash();
    vv_reports_enforce_flag_rate_limit($pdo, $clientHash);

    $pdo->beginTransaction();
    try {
        $reportStmt = $pdo->prepare(
            'SELECT id, moderation_status
             FROM weather_reports
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $reportStmt->execute([$reportId]);
        $report = $reportStmt->fetch();

        if (!$report) {
            $pdo->rollBack();
            vv_error('Fant ikke rapporten.', 404);
        }
        if ((string) ($report['moderation_status'] ?? 'visible') === 'hidden') {
            $pdo->commit();
            return [
                'duplicate' => true,
                'hidden' => true,
                'message' => 'Rapporten er allerede tatt ut av offentlig visning.',
            ];
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO weather_report_flags
                (report_id, client_hash, reason, details)
             VALUES (?, ?, ?, ?)'
        );
        $insert->execute([
            $reportId,
            $clientHash,
            $reason,
            $details !== '' ? $details : null,
        ]);
        $duplicate = $insert->rowCount() === 0;

        if ($duplicate) {
            $pdo->commit();
            return [
                'duplicate' => true,
                'hidden' => false,
                'message' => 'Du har allerede rapportert dette innlegget.',
            ];
        }

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM weather_report_flags WHERE report_id = ?'
        );
        $countStmt->execute([$reportId]);
        $flagCount = (int) $countStmt->fetchColumn();
        $autoHideThreshold = max(2, min(10, (int) vv_env('REPORT_AUTO_HIDE_FLAGS', '3')));
        $hidden = $flagCount >= $autoHideThreshold;
        $status = $hidden ? 'hidden' : 'review';
        $moderationReason = $hidden
            ? "Automatisk skjult etter {$flagCount} uavhengige varsler."
            : (vv_reports_flag_reasons()[$reason] ?? 'Annet');

        $update = $pdo->prepare("
            UPDATE weather_reports
            SET moderation_status = ?,
                moderation_reason = ?,
                moderated_at = NOW(),
                flag_count = ?
            WHERE id = ? AND moderation_status <> 'hidden'
        ");
        $update->execute([$status, $moderationReason, $flagCount, $reportId]);
        $pdo->commit();

        return [
            'duplicate' => false,
            'hidden' => $hidden,
            'message' => 'Takk. Rapporten er sendt til vurdering.',
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function vv_reports_cleanup(PDO $pdo): void
{
    $daysSetting = trim((string) vv_env('REPORT_RETENTION_DAYS', ''));
    $hoursSetting = trim((string) vv_env('REPORT_RETENTION_HOURS', ''));
    $retentionHours = $daysSetting !== ''
        ? ((int) $daysSetting * 24)
        : ($hoursSetting !== '' ? (int) $hoursSetting : 720);
    $retentionHours = max(168, min(720, $retentionHours));

    $pdo->exec("DELETE FROM weather_reports WHERE created_at < (NOW() - INTERVAL {$retentionHours} HOUR)");
    $pdo->exec('DELETE FROM weather_report_rate_limits WHERE expires_at <= NOW()');
    $pdo->exec(
        'UPDATE weather_reports
         SET latitude = ROUND(latitude, 2), longitude = ROUND(longitude, 2)
         WHERE (latitude IS NOT NULL AND latitude <> ROUND(latitude, 2))
            OR (longitude IS NOT NULL AND longitude <> ROUND(longitude, 2))'
    );
}

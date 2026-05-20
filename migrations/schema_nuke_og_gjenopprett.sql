-- =============================================================================
-- Værvakt.no: SLETT alle værrapporter og gjenopprett tabellen «reports» fra scratch
-- =============================================================================
-- ADVARSEL: Alle rader i «reports» slettes permanent. Ta backup først hvis du
-- trenger historikk.
--
-- Kjør i phpMyAdmin (eller mysql-klient) med RIKTIG database valgt — samme som
-- DB_NAME i .env (f.eks. 231354_vakt). Eksempel:
--   USE `231354_vakt`;
--   deretter lim inn og kjør denne filen.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP TABLE IF EXISTS `reports`;

CREATE TABLE `reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reporter_name` VARCHAR(120) NOT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `temperature_c` DECIMAL(5, 2) NOT NULL,
    `conditions` TEXT NOT NULL,
    `weather_icon` VARCHAR(64) DEFAULT NULL COMMENT 'Valgfritt: Lucide-navn eller kort kode',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reports_created_at` (`created_at`),
    KEY `idx_reports_reporter` (`reporter_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

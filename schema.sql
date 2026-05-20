-- Værvakt.no: tabeller for værrapporter og aktivitet.
-- Kjør mot MySQL/MariaDB (utf8mb4). Opprett database først eller bruk eksisterende.
--
-- Vil du tømme gammelt skjema og starte på nytt? Bruk i stedet:
--   schema_nuke_og_gjenopprett.sql  (DROP TABLE reports + CREATE på nytt)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `reports` (
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

-- ---------------------------------------------------------------------------
-- Push-notifikasjon subscriptions (Web Push API)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint` TEXT NOT NULL,
    `p256dh` TEXT NOT NULL,
    `auth` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_subscriptions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Migrasjon / typiske feil på drift
-- ---------------------------------------------------------------------------
-- 1) Feilmelding «Table '…reports' doesn't exist»: kjør hele denne filen mot
--    riktig database (samme som DB_NAME), eller opprett tabellen manuelt.
-- 2) Eksisterende reports uten valgfrie kolonner — legg til:
--    ALTER TABLE `reports` ADD COLUMN `weather_icon` VARCHAR(64) DEFAULT NULL
--      COMMENT 'Valgfritt: Lucide-navn eller kort kode' AFTER `conditions`;
--    ALTER TABLE `reports` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `reporter_name`;
-- 3) Eldre navn på temperaturkolonne (PHP-koden aksepterer `temperature` som alias):
--    ALTER TABLE `reports` CHANGE COLUMN `temperature` `temperature_c` DECIMAL(5,2) NOT NULL;
-- 4) Mangler «reporter_name» men har f.eks. «name» — enten kjør migrasjon_reporter_name.sql
--    eller la PHP bruke den gamle kolonnen automatisk (ingen ALTER nødvendig).
-- 5) Mangler tekstfelt for vær/forhold (conditions m.fl.): se migrasjon_værtekst.sql
--    og utvidet kolonneliste i functions.php (vaervakt_reports_skjema).

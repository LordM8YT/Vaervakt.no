-- Værvakt.no: Legg til `conditions` i `reports` (HeidiSQL-kompatibel)
-- Denne filen gjør sjekker før hver operasjon for å unngå feil i eldre MySQL/MariaDB.

SET NAMES utf8mb4;

-- Legg til kolonnen hvis nødvendig
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'conditions') = 0,
  'ALTER TABLE `reports` ADD COLUMN `conditions` TEXT NULL COMMENT ''Vær/forhold (Værvakt)''',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fyll tomme rader hvis kolonnen nå finnes
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'conditions') > 0,
  'UPDATE `reports` SET `conditions` = '''' WHERE `conditions` IS NULL',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sett NOT NULL
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'conditions') > 0,
  'ALTER TABLE `reports` MODIFY COLUMN `conditions` TEXT NOT NULL',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'OK' AS status;

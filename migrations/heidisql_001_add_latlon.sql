-- Værvakt.no: Legg til latitude/longitude på en HeidiSQL-kompatibel måte
-- Denne filen sjekker om tabellen og kolonnen finnes før den kjører ALTER.
-- Kjør med HeidiSQL ved å velge riktig database i venstrepanelet og åpne denne filen i en spørringsfane.

SET NAMES utf8mb4;

-- weather_reports: latitude
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'weather_reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'weather_reports' AND column_name = 'latitude') = 0,
  'ALTER TABLE `weather_reports` ADD COLUMN `latitude` DECIMAL(10,6) NULL AFTER `location`',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- weather_reports: longitude
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'weather_reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'weather_reports' AND column_name = 'longitude') = 0,
  'ALTER TABLE `weather_reports` ADD COLUMN `longitude` DECIMAL(10,6) NULL AFTER `latitude`',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- reports (eldre schema): latitude
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'latitude') = 0,
  'ALTER TABLE `reports` ADD COLUMN `latitude` DECIMAL(10,6) NULL AFTER `location`',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- reports (eldre schema): longitude
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'longitude') = 0,
  'ALTER TABLE `reports` ADD COLUMN `longitude` DECIMAL(10,6) NULL AFTER `latitude`',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ferdig
SELECT 'OK' AS status;

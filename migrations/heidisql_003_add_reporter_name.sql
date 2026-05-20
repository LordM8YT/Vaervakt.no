-- Værvakt.no: Legg til `reporter_name` i `reports` (HeidiSQL-kompatibel)
-- Filen legger til kolonnen hvis den mangler. Backfill eksempler er kommentert.

SET NAMES utf8mb4;

-- Legg til kolonnen hvis den ikke finnes
SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reports') > 0
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'reporter_name') = 0,
  'ALTER TABLE `reports` ADD COLUMN `reporter_name` VARCHAR(120) NULL AFTER `id`',
  'SELECT 1'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill-eksempler (kjør manuelt etter inspeksjon):
-- UPDATE `reports` SET `reporter_name` = `username` WHERE (reporter_name IS NULL OR reporter_name = '') AND `username` IS NOT NULL;
-- UPDATE `reports` SET `reporter_name` = `name` WHERE (reporter_name IS NULL OR reporter_name = '') AND `name` IS NOT NULL;

SELECT 'OK' AS status;

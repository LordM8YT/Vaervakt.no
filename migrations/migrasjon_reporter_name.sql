-- Værvakt.no: legg til «reporter_name» når eldre tabell bruker f.eks. bare «name».
-- Kjør KUN delene som passer din tabell (sjekk med SHOW COLUMNS FROM reports;).

SET NAMES utf8mb4;

-- A) Du har kolonne «name» (eller lignende) og vil standardisere til «reporter_name»:
-- ALTER TABLE `reports` ADD COLUMN `reporter_name` VARCHAR(120) NULL AFTER `id`;
-- UPDATE `reports` SET `reporter_name` = LEFT(TRIM(`name`), 120) WHERE `reporter_name` IS NULL OR `reporter_name` = '';
-- UPDATE `reports` SET `reporter_name` = 'Ukjent' WHERE `reporter_name` IS NULL OR TRIM(`reporter_name`) = '';
-- ALTER TABLE `reports` MODIFY `reporter_name` VARCHAR(120) NOT NULL;
-- (Valgfritt etter verifisering:) ALTER TABLE `reports` DROP COLUMN `name`;

-- B) Eller: behold gammel struktur — PHP støtter lesing/skriving via «name», «reporter»,
--    «username», «navn» m.fl. så lenge øvrige felt finnes (conditions eller description, osv.).

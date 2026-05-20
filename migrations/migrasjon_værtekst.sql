-- Værvakt.no: legg til «conditions» når tabellen ikke har noe tekstfelt PHP kjenner igjen.
-- Kjør KUN hvis SHOW COLUMNS FROM reports; ikke viser «conditions» eller andre kolonner
-- som forhold / beskrivelse / description (se lista i functions.php).
-- Feiler med «Duplicate column» hvis «conditions» allerede finnes — da er migrasjonen unødvendig.

SET NAMES utf8mb4;

ALTER TABLE `reports` ADD COLUMN `conditions` TEXT NULL COMMENT 'Vær/forhold (Værvakt)';
UPDATE `reports` SET `conditions` = '' WHERE `conditions` IS NULL;
ALTER TABLE `reports` MODIFY COLUMN `conditions` TEXT NOT NULL;

-- ---------------------------------------------------------------------------
-- Valgfritt: kopier fra eksisterende kolonne i stedet for tom streng
-- (tilpass kolonnenavn, kjør FØR MODIFY NOT NULL over, og fjern ADD over
-- hvis du bare skal fylle en kolonne som allerede finnes).
-- UPDATE `reports` SET `conditions` = `gammel_kolonne` WHERE `conditions` = '' OR `conditions` IS NULL;

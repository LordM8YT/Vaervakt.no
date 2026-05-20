-- Værvakt.no: migrasjon for å legge til GPS-koordinater i rapporttabellene
-- Kjør i riktig database (samme som DB_NAME i .env). TA BACKUP FØR KJØRING.
-- Eksempel backup:
--   mysqldump -u user -p your_database > backup_before_add_latlon.sql

SET NAMES utf8mb4;

-- -----------------------------
-- Legg til i `weather_reports` (ny/foretrukket tabell)
-- -----------------------------
ALTER TABLE `weather_reports` 
  ADD COLUMN `latitude` DECIMAL(10,6) NULL AFTER `location`,
  ADD COLUMN `longitude` DECIMAL(10,6) NULL AFTER `latitude`;

-- -----------------------------
-- Legg til i `reports` (eldre schema)
-- -----------------------------
ALTER TABLE `reports`
  ADD COLUMN `latitude` DECIMAL(10,6) NULL AFTER `location`,
  ADD COLUMN `longitude` DECIMAL(10,6) NULL AFTER `latitude`;

-- Valgfritt: opprett index for raskere geografiske søk (ikke spatial)
-- CREATE INDEX idx_reports_lat_lon ON weather_reports (latitude, longitude);

-- BACKFILL: ingen automatisk backfill utføres her. Hvis du har en ekstern kilde
-- eller kan utlede koordinater fra `location`, kjør en separat UPDATE.

-- Feilsøking: Hvis kolonnen allerede finnes, vil ALTER TABLE feile.
-- Sjekk først med for eksempel:
--   SELECT COUNT(*) FROM information_schema.columns
--     WHERE table_schema = DATABASE() AND table_name = 'weather_reports' AND column_name = 'latitude';

-- END

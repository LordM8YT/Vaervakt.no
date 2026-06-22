# Værvakt v2 Handoff

Dette er en ren v2-start etter at legacy-appen ble arkivert på GitHub.

Backup av gammel app:
- Tag: `legacy-before-v2-rebuild-20260620`
- Branch: `backup/legacy-before-v2-rebuild-20260620`

## Struktur

- `index.html`: App-shell og views.
- `assets/css/app.css`: All styling.
- `assets/js/app.js`: Frontendlogikk.
- `api/bootstrap.php`: Felles konfig, `.env`, PDO og helpers.
- `api/weather.php`: MET-varsel og valgfri Yr badetemperatur.
- `api/reports.php`: Lokale værrapporter.
- `api/hub.php`: Værhub med navn + PIN.
- `api/track.php`: Anonym besøkslogging for admin-statistikk.
- `api/geocode.php`: Stedssøk og reverse geocoding via Nominatim.
- `admin/index.php`: Desktop-only adminpanel med rapporter, bildeglimt og trafikk.
- `manifest.json` og `service-worker.js`: PWA.

## Personvern

Værhub bruker bare visningsnavn og PIN. PIN lagres med `password_hash()`. Det samles ikke inn e-post eller telefonnummer i v2-starten.

## Neste naturlige steg

1. Mer avansert moderering i admin, for eksempel brukerblokkering og massehandlinger.
2. Kartvisning når rapporter med koordinater finnes.
3. Bedre badetemperatur-søk og badeplassforslag.
4. Push-varsler når VAPID og varslingsstrategi er klar.

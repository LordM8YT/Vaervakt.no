# Værvakt v2 Handoff

Dette er en ren v2-start etter at legacy-appen ble arkivert på GitHub.

Backup av gammel app:
- Tag: `legacy-before-v2-rebuild-20260620`
- Branch: `backup/legacy-before-v2-rebuild-20260620`

## Struktur

- `index.html`: React-buildens app-shell.
- `static/`: Bygget React, CSS og JS fra `Vaervakt-react`.
- `assets/js/live-enhancements.js`: Lite klientlag lastet før React-bundlen. Legger kart og rapporthistorikk bak egne knapper uten å endre minifisert build.
- `api/bootstrap.php`: Felles konfig, `.env`, PDO og helpers.
- `api/weather.php`: MET-varsel og valgfri Yr badetemperatur for visning.
- `api/reports.php`: Lokale værrapporter. Public GET viser som standard bare rapporter fra siste 7 dager; bruk `freshness=all` eller `maxAgeDays=0` for historikk.
- `api/glimpses.php`: Værglimt med bilde, levetid og automatisk utløp.
- `api/bath-reports.php`: Innsending av badetemperaturer, lokal logging og forwarding til Yr.
- `api/track.php`: Anonym besøkslogging for admin-statistikk.
- `api/geocode.php`: Stedssøk og reverse geocoding via Nominatim.
- `admin/index.php`: Desktop-only adminpanel med rapporter, badetemperaturer, bildeglimt og trafikk.
- `manifest.json` og `service-worker.js`: PWA.

## Miljøvariabler

- `YR_BATH_API_KEY`: API-nøkkel fra Yr. Brukes både til å hente og sende badetemperaturer.
- `SUPPORT_URL`: Vipps-/støttelenke.
- `ADMIN_USERNAME`, `ADMIN_PASSWORD` eller `ADMIN_PASSWORD_HASH`: Admininnlogging.
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, valgfritt `DB_PORT`: MySQL.

## Personvern

Værglimt bruker bare visningsnavn og PIN. PIN lagres med `password_hash()`. Det samles ikke inn e-post eller telefonnummer i v2-starten.

Badetemperaturer lagrer badeplassnavn, temperatur, koordinater, valgfritt visningsnavn og status fra Yr. API-nøkkelen lagres aldri i databasen eller frontend.

## Badetemperatur til Yr

Brukeren sender badeplassnavn og temperatur fra appen. Backend legger til koordinater, tidspunkt og `heatedWater`, lagrer forsøket lokalt i `bath_temperature_reports`, og sender JSON videre til `https://badetemperaturer.yr.no/api/registrere`.

Yr krever at badeplassnavnet kan matches mot Yr sitt søk eller nærmeste sted. Hvis Yr avviser innsendingen, blir status `failed` i adminpanelet.

## Neste naturlige steg

1. Badeplassforslag/autocomplete mot Yr, slik at brukeren oftere velger et navn Yr kan matche.
2. Flytte kart og rapporthistorikk inn i kildeappen når React-kilden oppdateres, slik at enhancement-laget kan fjernes.
3. Moderering eller rate limiting på badetemp hvis innsendingen blir populær.
4. Push-varsler når VAPID og varslingsstrategi er klar.

# Værvakt v2 Handoff

Dette er en ren v2-start etter at legacy-appen ble arkivert på GitHub.

Backup av gammel app:
- Tag: `legacy-before-v2-rebuild-20260620`
- Branch: `backup/legacy-before-v2-rebuild-20260620`

## Struktur

- `index.html`: React-buildens app-shell.
- `static/`: Bygget React, CSS og JS fra `Vaervakt-react`.
- `assets/js/live-enhancements.js`: Lite klientlag lastet før React-bundlen. Legger rapporthistorikk, favoritter, UV-status og push-registrering uten å endre minifisert build.
- `api/bootstrap.php`: Felles konfig, `.env`, PDO og helpers.
- `api/weather.php`: MET-varsel og valgfri Yr badetemperatur for visning.
- `api/reports.php`: Lokale værrapporter. Public GET viser bare synlige rapporter fra maksimalt de siste 7 dagene. Eksakte rapportkoordinater returneres ikke; avstand beregnes på serveren.
- `api/report-lib.php`: Tabeller, rate limiting, misbruksvarsler, modereringsstatus og automatisk opprydding for værrapporter.
- `api/glimpses.php`: Værglimt med bilde, levetid og automatisk utløp.
- `api/bath-reports.php`: Innsending av badetemperaturer, lokal logging og forwarding til Yr.
- `api/track.php`: Anonym besøkslogging for admin-statistikk.
- `api/geocode.php`: Stedssøk via Nominatim og norsk reverse geocoding via Kartverket, med Nominatim som reserve.
- `admin/index.php`: Desktop-only adminpanel med rapporter, badetemperaturer, bildeglimt og trafikk.
- `manifest.json` og `service-worker.js`: PWA.

## Miljøvariabler

- `YR_BATH_API_KEY`: API-nøkkel fra Yr. Brukes både til å hente og sende badetemperaturer.
- `SUPPORT_URL`: Vipps-/støttelenke.
- `ADMIN_USERNAME`, `ADMIN_PASSWORD` eller `ADMIN_PASSWORD_HASH`: Admininnlogging.
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, valgfritt `DB_PORT`: MySQL.
- `REPORT_RETENTION_DAYS`: Lagring for moderering, maksimalt 30 dager.
- `REPORT_RATE_LIMIT` og `REPORT_RATE_WINDOW_MINUTES`: Innsendingstakt per pseudonym klient.
- `REPORT_FLAG_RATE_LIMIT`, `REPORT_FLAG_RATE_WINDOW_MINUTES` og `REPORT_AUTO_HIDE_FLAGS`: Grenser for misbruksvarsler og automatisk skjuling.

## Personvern

Værglimt bruker bare visningsnavn og PIN. PIN lagres med `password_hash()`. Det samles ikke inn e-post eller telefonnummer i v2-starten.

Badetemperaturer lagrer badeplassnavn, temperatur, koordinater, valgfritt visningsnavn og status fra Yr. API-nøkkelen lagres aldri i databasen eller frontend.

## Badetemperatur til Yr

Brukeren sender badeplassnavn og temperatur fra appen. Backend legger til koordinater, tidspunkt og `heatedWater`, lagrer forsøket lokalt i `bath_temperature_reports`, og sender JSON videre til `https://badetemperaturer.yr.no/api/registrere`.

Yr krever at badeplassnavnet kan matches mot Yr sitt søk eller nærmeste sted. Hvis Yr avviser innsendingen, blir status `failed` i adminpanelet.

## Neste naturlige steg

Lokale værrapporter har nå tidsfilter, antall, siste aktivitet, avstand, lagring av sist valgte sted og GPS-autofyll. API-et validerer temperatur, værtype, koordinater og feltlengder, begrenser innsendinger per klient og har misbruksrapportering med modereringskø. Rapporter skjules automatisk etter et konfigurerbart antall uavhengige varsler og slettes automatisk etter `REPORT_RETENTION_DAYS`.

1. Vise eksisterende godkjente værstasjoner, status og siste måling i appen.
2. Bygge en samlet «Værvakt Nå»-status av MET-varsel, lokale stasjoner og brukerrapporter.
3. Badeplassforslag/autocomplete mot Yr, slik at brukeren oftere velger et navn Yr kan matche.
4. Utvide rate limiting til badetemperatur, Værglimt og øvrige offentlige skriveendepunkter.
5. Push-varsler når VAPID og varslingsstrategi er klar.

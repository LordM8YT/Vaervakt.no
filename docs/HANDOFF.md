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
- `api/bath-locations.php`: Serverproxy for søk etter godkjente Yr-badeplasser.
- `api/reports.php`: Lokale værrapporter. Public GET viser bare synlige rapporter fra maksimalt de siste 7 dagene. Eksakte rapportkoordinater returneres ikke; avstand beregnes på serveren.
- `api/report-lib.php`: Tabeller, rate limiting, misbruksvarsler, modereringsstatus og automatisk opprydding for værrapporter.
- `api/glimpses.php`: Avviklet Værglimt-endepunkt. Rydder eldre data og svarer 410.
- `api/bath-reports.php`: Validert innsending av badetemperaturer, lokal leveringslogg og videresending til Yr.
- `api/rate-limit-lib.php`: Felles, pseudonymt og kortvarig rate-limit-lager for offentlige handlinger.
- `api/push.php`: Skjult subscribe/update/unsubscribe-lager for push v1; frontend er ikke aktivert.
- `api/hub.php`: Avviklet hub-endepunkt. Rydder eldre profiler, innlegg og stemmer og svarer 410.
- `api/track.php`: Deaktivert individuell besøkslogging. Rydder eldre besøksrader.
- `api/geocode.php`: Stedssøk via Nominatim og norsk reverse geocoding via Kartverket, med Nominatim som reserve.
- `admin/index.php`: Desktop-only adminpanel med rapporter, badetemperaturer, bildeglimt og trafikk.
- `manifest.json` og `service-worker.js`: PWA.
- `docs/push-v1.md`: Besluttet første varseltyper, samtykkeflyt, dataminimering og teknisk leveringsgate.

## Miljøvariabler

- `YR_BATH_API_KEY`: API-nøkkel fra Yr. Brukes både til å hente og sende badetemperaturer.
- `SUPPORT_URL`: Vipps-/støttelenke.
- `ADMIN_USERNAME`, `ADMIN_PASSWORD` eller `ADMIN_PASSWORD_HASH`: Admininnlogging.
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, valgfritt `DB_PORT`: MySQL.
- `REPORT_RETENTION_DAYS`: Lagring for moderering, maksimalt 30 dager.
- `REPORT_RATE_LIMIT` og `REPORT_RATE_WINDOW_MINUTES`: Innsendingstakt per pseudonym klient.
- `REPORT_FLAG_RATE_LIMIT`, `REPORT_FLAG_RATE_WINDOW_MINUTES` og `REPORT_AUTO_HIDE_FLAGS`: Grenser for misbruksvarsler og automatisk skjuling.
- `BATH_RATE_LIMIT` og `BATH_RATE_WINDOW_MINUTES`: Innsendingstakt for badetemperaturer per pseudonym klient.
- `PUSH_RATE_LIMIT`, `PUSH_RATE_WINDOW_MINUTES` og `PUSH_SUBSCRIPTION_RETENTION_DAYS`: Misbruksvern og slettetid for push-abonnementer.

## Personvern

Værglimt, hub, stemmer og individuell besøkslogging er avviklet. Endepunktene
rydder eldre data i stedet for å ta imot nytt innhold.

Badetemperaturer lagrer badeplassnavn, Yr-ID, temperatur, koordinater og status
fra Yr i en teknisk leveringslogg. Innsendernavn lagres ikke. API-nøkkelen
lagres aldri i databasen eller frontend. Innsendingstakten begrenses med et
kortvarig HMAC-hash; IP-adressen lagres ikke i klartekst i rate-limit-tabellen.

## Badetemperatur til Yr

Brukeren søker først etter en godkjent Yr-badeplass via Værvakts serverproxy. Appen sender valgt `locationId` og temperatur. Backend bekrefter ID-en mot Yr, overstyrer navn og koordinater med verdiene fra Yr, legger til tidspunkt og `heatedWater`, lagrer forsøket lokalt i `bath_temperature_reports`, og sender JSON videre til `https://badetemperaturer.yr.no/api/registrere`.

Fritekst godtas ikke lenger ved innsending fra appen. Hvis Yr ikke kan bekrefte badeplassen eller avviser innsendingen, får brukeren en tydelig melding og leveringsstatusen blir synlig i adminpanelet.

## Neste naturlige steg

Lokale værrapporter har nå tidsfilter, antall, siste aktivitet, avstand, lagring av sist valgte sted og GPS-autofyll. API-et validerer temperatur, værtype, koordinater og feltlengder, begrenser innsendinger per klient og har misbruksrapportering med modereringskø. Rapporter skjules automatisk etter et konfigurerbart antall uavhengige varsler og slettes automatisk etter `REPORT_RETENTION_DAYS`.

1. Fullføre testmatrisen for åpen beta på mobil, nettbrett og desktop.
2. Lukke eventuelle blokkerende feil fra betatesten.
3. Bygge abonnement-endepunkt og senderjobb etter leveringsgaten i `docs/push-v1.md`.

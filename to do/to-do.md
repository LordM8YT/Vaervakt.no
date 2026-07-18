# Værvakt.no – Todo og roadmap

Denne filen er den samlede todo-listen for Værvakt.no.

Større oppgaver kan også opprettes som egne GitHub Issues. Når en oppgave er fullført, endres `[ ]` til `[x]`.

Sist oppdatert: 18. juli 2026. Oppgavene under **Nå – prioritert rekkefølge** gjennomføres ovenfra og ned. Resten av dokumentet er gruppert etter fagområde.

---

## Nå – prioritert rekkefølge

1. [x] Moderering og misbruksvern for værrapporter
   - [x] La brukere rapportere feil eller misbruk
   - [x] Legg til modereringsstatus og handlinger i admin
   - [x] Skjul eller slett rapporter som er moderert
   - [x] Lag automatisk utløp/opprydding for gamle rapporter

2. [ ] Vis eksisterende værstasjoner i appen
   - [x] API for godkjente stasjoner og siste måling finnes
   - [ ] Hent nærmeste godkjente stasjoner for valgt sted eller GPS-posisjon
   - [ ] Vis online/offline og tidspunkt for siste oppdatering
   - [ ] Skill stasjonsmålinger tydelig fra brukerrapporter

3. [ ] Fullfør stedssøk og samlet områdestatus
   - [x] Brukere kan søke etter et sted
   - [x] Valgt sted huskes lokalt
   - [x] Vis rapporter, antall og siste aktivitet for valgt sted
   - [ ] Vis tilgjengelige værstasjoner i området
   - [ ] Oppsummer rapporter, stasjoner og siste aktivitet i én områdestatus

4. [ ] Badeplassforslag fra Yr
   - [ ] Legg til autocomplete for badeplassnavn
   - [ ] Bruk valgt Yr-badeplass og koordinater ved innsending
   - [ ] Vis en tydelig melding hvis Yr ikke kan matche badeplassen

5. [ ] Utvid misbruksvernet til øvrige offentlige skriveendepunkter
   - [x] Rate limiting og feltvalidering for værrapporter
   - [ ] Rate limiting for Værglimt
   - [ ] Rate limiting for badetemperaturer
   - [ ] Rate limiting for hub-innlegg, stemmer og besøkslogging

6. [ ] Avklar første versjon av push-varsler
   - [ ] Velg hvilke varsler som skal lanseres først
   - [ ] Definer samtykke, geografisk område og varslingsgrenser
   - [ ] Test VAPID-oppsett og levering før varsler aktiveres

---

## Værstasjoner

- [ ] Universelt værstasjons-API
  - [x] JSON-endepunkt for innsending og lesing
  - [x] Unik, hashet API-nøkkel per stasjon
  - [x] Temperatur
  - [x] Luftfuktighet
  - [x] Lufttrykk
  - [x] Vindstyrke
  - [ ] Vindkast
  - [x] Vindretning
  - [x] Nedbør
  - [x] Måletidspunkt
  - [x] Dokumentert Home Assistant-eksempel

- [ ] Verifiserte værstasjoner
  - [x] Godkjenning og deaktivering i admin
  - [x] Kun godkjente stasjoner vises i offentlig API
  - [ ] Vis merke for verifisert stasjon i appen
  - [ ] Vis om stasjonen er online
  - [ ] Vis siste oppdatering
  - [ ] Vis datakvalitet
  - [ ] Skill mellom automatisk måling og brukerrapport

- [ ] Home Assistant-integrasjon
  - [x] REST-endepunkt
  - [x] Ferdig oppsettseksempel
  - [ ] Automatisk identifisering av sensorer der det er mulig

- [ ] CumulusMX- og WeeWX-integrasjon
  - Ferdig opplastingsadresse
  - Dokumenter støttede felter
  - Eksempelkonfigurasjoner

- [ ] Ecowitt-integrasjon
  - Custom Weather Server
  - Generer ferdig serveradresse
  - Bruk eksisterende stasjons-ID og nøkkel
  - QR-kode eller enkel oppsettsveiviser
  - Test tilkoblingen før aktivering

- [ ] WeatherLink-integrasjon
  - Koble til via WeatherLink API
  - Automatisk hente stasjonsinformasjon
  - Automatisk hente målinger

- [ ] Netatmo-integrasjon
  - OAuth-innlogging
  - Automatisk henting av stasjoner
  - Automatisk synkronisering av målinger
  - Sikker lagring av tokens

- [ ] Datakvalitet for værstasjoner
  - Avvis åpenbart urealistiske målinger
  - Oppdag sensorer som sitter fast
  - Sammenlign med nærliggende stasjoner
  - Merk gamle målinger
  - Sett stasjonen som offline etter manglende data

- [ ] Stasjonssider
  - Offentlig side for hver stasjon
  - Stasjonsnavn
  - Eier
  - Plassering
  - Status
  - Siste målinger
  - Sensoroversikt
  - Historikk og grafer

---

## Historikk og grafer

- [ ] Temperaturgraf
  - Siste time
  - Siste 24 timer
  - Siste 7 dager
  - Siste 30 dager

- [ ] Luftfuktighetsgraf

- [ ] Vindgraf
  - Gjennomsnittlig vind
  - Vindkast
  - Vindretning

- [ ] Lufttrykksgraf

- [ ] Nedbørsgraf

- [ ] Eksport av data
  - CSV
  - JSON

---

## Kart

- [ ] Interaktivt værkart

- [ ] Vis værstasjoner på kartet

- [ ] Vis brukerrapporter på kartet

- [ ] Vis farevarsler på kartet

- [ ] Vis badetemperaturer på kartet

- [ ] Kartfiltre
  - Værstasjoner
  - Rapporter
  - Farevarsler
  - Badetemperaturer
  - Online/offline stasjoner

- [ ] Popup ved klikk på kartpunkt
  - Temperatur
  - Værtype
  - Tidspunkt
  - Navn på sted eller stasjon
  - Lenke til detaljside

---

## Badetemperaturer og Yr

- [x] Hente ferske badetemperaturer fra Yr for valgt område

- [x] Sende badetemperaturer til Yr via serveren

- [ ] Badeplassforslag/autocomplete mot Yr
  - Søk etter godkjente badeplassnavn
  - Bruk ID og koordinater fra valgt treff
  - Reduser avviste innsendinger til Yr

- [ ] Egen søkeside for badeplasser

- [ ] Historikk for badetemperaturer

- [ ] Innsendingsskjema for badetemperatur
  - [x] Badeplass
  - [x] Temperatur
  - [x] Oppvarmet vann
  - [x] Koordinater fra valgt sted/GPS
  - [ ] Måletidspunkt
  - [ ] Målemetode
  - [ ] Navn på innsender

- [x] Tydelig kreditering
  - [x] Vis teksten `Badetemperaturer levert av Yr` direkte ved dataene

- [x] Sikker håndtering av Yr API-nøkkel
  - [x] Nøkkelen brukes kun server-side
  - [x] Nøkkelen ligger ikke i JavaScript eller offentlig repository
  - [x] Nøkkelen leses fra miljøvariabel eller privat konfigurasjonsfil

---

## Rapporter fra brukere

- [x] Rapporter feil eller misbruk

- [x] Moderering av rapporter
  - [x] Modereringsstatus i databasen
  - [x] Handlinger og oversikt i admin
  - [x] Skjul modererte rapporter fra offentlig API

- [x] Automatisk utløp og opprydding for gamle rapporter

- [ ] Filtrering av rapporter
  - [x] Sted
  - [ ] Værtype
  - [x] Tidsrom
  - [x] Avstand
  - [ ] Temperaturområde

- [ ] Skill tydelig mellom datakilder
  - [x] Brukerrapport og badetemperatur
  - [ ] Værstasjonsmåling
  - [ ] Offisielt farevarsel

- [ ] Bilder på rapporter

- [ ] Reaksjoner på rapporter

---

## Varsler

- [ ] Pushvarsel ved torden

- [ ] Pushvarsel ved sterk vind

- [ ] Pushvarsel ved frost

- [ ] Pushvarsel ved kraftig regn

- [ ] Pushvarsel ved snøfare

- [ ] Pushvarsel ved nye rapporter i nærheten

- [ ] Pushvarsel når badetemperaturen passerer valgt temperatur

- [ ] Personlige varslingsgrenser
  - Temperatur
  - Vindstyrke
  - Nedbør
  - Badetemperatur

---

## OBS-overlay

- [ ] OBS Overlay v2

- [ ] Automatisk oppdatering

- [ ] Klokke

- [ ] Siste rapport

- [ ] Farevarsler

- [ ] Ticker nederst

- [ ] Animerte værikoner

- [ ] Banner mode i overlay

- [ ] Tilpassbare farger og størrelse

- [ ] Transparent bakgrunn

- [ ] Egne nettadresser per overlay-oppsett

---

## Dynamisk design

- [ ] Dynamisk bakgrunn basert på været
  - Sol
  - Regn
  - Snø
  - Torden
  - Skyet
  - Natt

- [ ] Animasjoner som kan deaktiveres

- [ ] God kontrast og universell utforming

- [ ] Mobilvennlig app-design

- [x] Lys og mørk modus

- [ ] PWA-forbedringer
  - Installerbar app
  - Offline-side
  - Oppdateringsvarsel
  - Bedre app-ikon og manifest

---

## Områdesider

- [ ] Lag en samlet side for hvert område

Eksempel:

```text
Drammen akkurat nå

17,8 °C
4 aktive værstasjoner
12 brukerrapporter siste time
2 rapporter om kraftig regn
Siste måling: 2 minutter siden
```

- [ ] Vis tilgjengelige værstasjoner

- [ ] Vis siste rapporter

- [ ] Vis offisielle farevarsler

- [ ] Vis badetemperaturer i nærheten

- [ ] Vis historikk og utvikling

---

## Adminpanel

- [x] Innlogging for administratorer med sesjon og CSRF-beskyttelse

- [ ] Opprett og administrer bannere

- [x] Opprett, godkjenn, deaktiver og slett værstasjoner

- [x] Regenerer stasjonsnøkler

- [x] Se siste stasjonsmålinger og mottatte data

- [ ] Se API-feil og tilkoblingsfeil

- [x] Moderer brukerrapporter
  - [x] Se og slette rapporter manuelt
  - [x] Modereringsstatus, begrunnelse og misbruksvarsler

- [ ] Blokker misbruk

- [x] Se status for og slette innsendte badetemperaturer

- [ ] Se statistikk
  - [x] Antall rapporter
  - [x] Aktive værstasjoner og stasjonsmålinger
  - [x] Besøk og aktive brukere
  - [ ] API-trafikk per endepunkt
  - [ ] Push-abonnementer

---

## Sikkerhet

- [ ] Rate limiting på API-endepunkter
  - [x] Værrapporter
  - [ ] Værglimt, badetemperatur og øvrige offentlige endepunkter

- [ ] Valider alle innsendte målinger
  - [x] Temperatur, værtype, koordinater og feltlengder for værrapporter
  - [x] Gyldige måleområder for værstasjoner
  - [ ] Gjennomgå Værglimt, badetemperatur og hub

- [ ] CSRF-beskyttelse på skjemaer
  - [x] Adminhandlinger
  - [ ] Vurder og dokumenter beskyttelsen for offentlige skriveendepunkter

- [ ] Begrens lengde på tekstfelt
  - [x] Værrapporter
  - [ ] Gjennomgå øvrige skjemaer og API-endepunkter

- [ ] Bruk prepared statements overalt

- [x] Ikke vis Yr-, database- eller stasjonsnøkler i frontend

- [x] Mulighet for å regenerere stasjonsnøkler

- [ ] Loggfør mistenkelig trafikk

- [x] Beskytt adminpanelet med innlogging, sikre sesjonscookies og CSRF-token

---

## API for tredjepartsutviklere

- [ ] Offentlig dokumentasjon
  - [x] Værstasjons-API
  - [ ] Samlet og publiserbar API-dokumentasjon

- [ ] API-nøkler
  - [x] Unike nøkler for værstasjoner
  - [ ] Nøkler og tilgangsnivåer for andre tredjepartsbrukere

- [x] Lesetilgang til offentlige værrapporter og godkjente værstasjoner

- [x] Innsending av værstasjonsdata

- [x] Innsending av brukerrapporter

- [ ] Webhooks

- [ ] Trafikkgrenser
  - [x] Innsending av værrapporter
  - [ ] Lesekall og øvrige skriveendepunkter

- [ ] Versjonert API, for eksempel `/api/v1/`

---

## Parkert foreløpig

- [ ] Banner mode for hendelser og kunngjøringer
  - Aktiv/av-bryter, innhold, lenke og tidsstyring
  - Typer: info, feiring, advarsel og drift
  - Støtte i app, OBS-overlay og ticker
  - Se GitHub Issue #19

- [ ] Automatisk tilkobling av værstasjoner
  - Veiviser og leverandørvalg
  - Automatisk oppdagelse av stasjon, sted og sensorer
  - Testmåling før aktivering
  - Se GitHub Issue #21

---

## Langsiktig

- [ ] Værvakt Connector for Windows, macOS og Raspberry Pi

- [ ] Automatisk oppdagelse av lokal værprogramvare

- [ ] Egne widgets som kan bygges inn på andre nettsider

- [ ] Åpen API-dokumentasjon

- [ ] Flere språk

- [ ] Mobilapp dersom PWA ikke er tilstrekkelig

- [ ] Samarbeid med kommuner, campingplasser, båthavner og badeplasser

---

## Fullført

Flytt ferdige oppgaver hit eller marker dem som `[x]`.

- [x] Forbedret rapportskjema
  - Tomme observasjonsfelt ved oppstart og etter innsending
  - Husk navn og sist valgte sted, men ikke temperatur eller værtype
  - Autofyll temperatur, værtype og sted ved bruk av GPS

- [x] Lokale rapporter nær valgt sted eller GPS-posisjon
  - Avstand, tidsfilter, antall rapporter og siste aktivitet
  - Temperatur med desimaler og korrekt lokalt stedsnavn

- [x] Stedssøk og lagring av sist valgte sted

- [x] Norsk reverse geokoding via Kartverket med Nominatim som reserve

- [x] Grunnplattform for private værstasjoner
  - Database, offentlig lese-API og autentisert innsending
  - Oppretting, godkjenning, nøkkelregenerering og målinger i admin
  - API-guide og Home Assistant-eksempel

- [x] Grunnintegrasjon for badetemperaturer fra og til Yr

- [x] Rate limiting og strengere validering av værrapporter

- [x] Moderering og misbruksvern for værrapporter
  - Brukere kan varsle om feil værdata, spam, upassende innhold og personopplysninger
  - Egen modereringskø med begrunnelse og handlinger i admin
  - Automatisk skjuling etter flere uavhengige varsler
  - Skjulte rapporter fjernes fra offentlig API
  - Automatisk sletting etter konfigurerbar lagringstid

- [x] Personvernvennlig sted og GPS-cache i Svelte-appen
  - Valgt sted huskes lokalt
  - GPS-koordinater beholdes med full nettleserpresisjon i lokal lagring
  - Badeplass-POI-er caches lokalt med automatisk utløp
  - GPS-oppslaget venter på høy presisjon og viser nøyaktigheten nettleseren rapporterer

- [x] Forbedret værvisning i Svelte-grensesnittet
  - Svelte 5 og Lucide-ikoner
  - UV-indeks vises korrekt med én desimal
  - Lys og mørk visning

- [x] Stream Deck-plugin v0.4
  - Svelte-basert innstillingspanel og knappgrafikk med Lucide
  - Vær, badetemperatur og siste lokale rapport
  - Temperaturer vises med én desimal
  - Automatisk oppdatering hvert 5. eller 10. minutt
  - Vanlige knappetrykk utløser ikke ekstra datahenting

- [x] Grunnleggende rapportsystem
- [x] Database for rapporter
- [x] Push subscription-tabell
- [x] Første plan for værstasjonsintegrasjoner
- [x] GitHub Issue for banner mode
- [x] GitHub Issue for automatisk værstasjonstilkobling

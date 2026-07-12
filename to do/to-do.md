# Værvakt.no – Todo og roadmap

Denne filen er den samlede todo-listen for Værvakt.no.

Større oppgaver kan også opprettes som egne GitHub Issues. Når en oppgave er fullført, endres `[ ]` til `[x]`.

---

## Høy prioritet

- [ ] Banner mode for hendelser og kunngjøringer
  - Aktiv/av-bryter
  - Tittel og melding
  - Valgfri lenke
  - Start- og utløpstid
  - Typer: info, feiring, advarsel og drift
  - Lukkeknapp
  - Husk lukking med localStorage
  - Mobilvennlig visning
  - Støtte i OBS-overlay og ticker
  - Kan brukes ved ekstremvær, driftsmeldinger, store sportsøyeblikk og lanseringer
  - Se GitHub Issue #19

- [ ] Automatisk tilkobling av værstasjoner
  - Lag en enkel veiviser
  - Brukeren velger leverandør
  - Logg inn eller koble til
  - Finn stasjonsnavn, sted, koordinater og sensorer automatisk
  - Vis testmåling før aktivering
  - Se GitHub Issue #21
  - Se også `README_STATION_INTEGRATION.md`

- [ ] Søk etter sted
  - Brukere skal kunne søke etter et sted
  - Vis rapporter fra valgt sted
  - Vis antall rapporter
  - Vis siste aktivitet
  - Vis tilgjengelige værstasjoner i området
  - Vis en samlet status for området

- [ ] Rapporter nær deg
  - Bruk GPS-posisjon
  - Finn rapporter i nærheten
  - Vis avstand til rapporten
  - Filtrer på tidsrom
  - Vis antall rapporter nær brukerens sted

- [ ] Forbedre rapportskjema
  - Alle felt skal være tomme første gang
  - Husk navn og sist brukte sted med localStorage
  - Ikke husk temperatur, værtype eller rapporttekst
  - Tøm rapportteksten etter vellykket innsending
  - Autofyll temperatur, værtype og sted ved bruk av GPS

---

## Værstasjoner

- [ ] Verifiserte værstasjoner
  - Vis merke for verifisert stasjon
  - Vis om stasjonen er online
  - Vis siste oppdatering
  - Vis datakvalitet
  - Skill mellom automatisk måling og brukerrapport

- [ ] Netatmo-integrasjon
  - OAuth-innlogging
  - Automatisk henting av stasjoner
  - Automatisk synkronisering av målinger
  - Sikker lagring av tokens

- [ ] WeatherLink-integrasjon
  - Koble til via WeatherLink API
  - Automatisk hente stasjonsinformasjon
  - Automatisk hente målinger

- [ ] Ecowitt-integrasjon
  - Custom Weather Server
  - Generer ferdig serveradresse
  - Generer unik stasjons-ID og nøkkel
  - QR-kode eller enkel oppsettsveiviser
  - Test tilkoblingen før aktivering

- [ ] Home Assistant-integrasjon
  - Webhook eller REST API
  - Ferdig oppsettseksempel
  - Automatisk identifisering av sensorer der det er mulig

- [ ] CumulusMX- og WeeWX-integrasjon
  - Ferdig opplastingsadresse
  - Dokumenter støttede felter
  - Eksempelkonfigurasjoner

- [ ] Universelt værstasjons-API
  - JSON-endepunkt
  - Unik API-nøkkel per stasjon
  - Temperatur
  - Luftfuktighet
  - Lufttrykk
  - Vindstyrke
  - Vindkast
  - Vindretning
  - Nedbør
  - Måletidspunkt

- [ ] Stasjonssider
  - Offentlig side for hver stasjon
  - Stasjonsnavn
  - Eier
  - Plassering
  - Status
  - Siste målinger
  - Sensoroversikt
  - Historikk og grafer

- [ ] Datakvalitet for værstasjoner
  - Avvis åpenbart urealistiske målinger
  - Oppdag sensorer som sitter fast
  - Sammenlign med nærliggende stasjoner
  - Merk gamle målinger
  - Sett stasjonen som offline etter manglende data

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

- [ ] Hente badetemperaturer fra Yr

- [ ] Sende badetemperaturer til Yr

- [ ] Egen søkeside for badeplasser

- [ ] Historikk for badetemperaturer

- [ ] Innsendingsskjema for badetemperatur
  - Badeplass
  - Temperatur
  - Måletidspunkt
  - Målemetode
  - Navn på innsender

- [ ] Tydelig kreditering
  - Vis teksten `Badetemperaturer levert av Yr` direkte ved dataene

- [ ] Sikker håndtering av Yr API-nøkkel
  - Nøkkelen skal kun brukes server-side
  - Ikke legg nøkkelen i JavaScript
  - Ikke legg nøkkelen i offentlig GitHub-repository
  - Bruk miljøvariabel eller privat konfigurasjonsfil

---

## Rapporter fra brukere

- [ ] Bilder på rapporter

- [ ] Reaksjoner på rapporter

- [ ] Rapporter feil eller misbruk

- [ ] Moderering av rapporter

- [ ] Filtrering etter:
  - Sted
  - Værtype
  - Tidspunkt
  - Avstand
  - Temperatur

- [ ] Automatisk utløp for gamle rapporter

- [ ] Skill tydelig mellom:
  - Brukerrapport
  - Værstasjonsmåling
  - Offisielt farevarsel
  - Badetemperatur

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

- [ ] Lys og mørk modus

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

- [ ] Innlogging for administratorer

- [ ] Opprett og administrer bannere

- [ ] Godkjenn og administrer værstasjoner

- [ ] Regenerer stasjonsnøkler

- [ ] Se sist mottatte data

- [ ] Se API-feil og tilkoblingsfeil

- [ ] Moderer brukerrapporter

- [ ] Blokker misbruk

- [ ] Administrer badetemperaturer

- [ ] Se statistikk
  - Antall rapporter
  - Aktive værstasjoner
  - Aktive brukere
  - API-trafikk
  - Push-abonnementer

---

## Sikkerhet

- [ ] Rate limiting på API-endepunkter

- [ ] Valider alle innsendte målinger

- [ ] CSRF-beskyttelse på skjemaer

- [ ] Begrens lengde på tekstfelt

- [ ] Bruk prepared statements overalt

- [ ] Ikke vis hemmelige nøkler i frontend

- [ ] Mulighet for å regenerere nøkler

- [ ] Loggfør mistenkelig trafikk

- [ ] Beskytt adminpanelet

---

## API for tredjepartsutviklere

- [ ] Offentlig dokumentasjon

- [ ] API-nøkler

- [ ] Lesetilgang til offentlige data

- [ ] Innsending av værstasjonsdata

- [ ] Innsending av brukerrapporter

- [ ] Webhooks

- [ ] Trafikkgrenser

- [ ] Versjonert API, for eksempel `/api/v1/`

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

- [x] Grunnleggende rapportsystem
- [x] Database for rapporter
- [x] Push subscription-tabell
- [x] Første plan for værstasjonsintegrasjoner
- [x] GitHub Issue for banner mode
- [x] GitHub Issue for automatisk værstasjonstilkobling

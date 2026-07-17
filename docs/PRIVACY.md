# Personvern og behandlingsoversikt

Sist gjennomgått: 17. juli 2026.

Dette dokumentet beskriver de tekniske personverntiltakene i Værvakt.no. Det
erstatter ikke behandlingsansvarliges løpende juridiske og organisatoriske
ansvar.

## Behandlingsansvarlig og kontakt

- Tjeneste: Værvakt.no
- Eier/kontakt: `LordM8YT`
- Personvernhenvendelser: `kontakt@værvakt.no`

Kontaktpunktet må overvåkes slik at innsyns-, rettings- og
slettingshenvendelser behandles innen lovens frister.

## Behandlinger

| Formål | Opplysninger | Grunnlag | Mottakere | Sletting |
| --- | --- | --- | --- | --- |
| Vær for valgt sted | Koordinater eller søket sted | GDPR 6(1)(b), levere forespurt funksjon | MET, Kartverket/Geonorge, OSMF Nominatim | Ikke lagret av Værvakt |
| Lokale rapporter | Valgfritt alias, værtype, temperatur, sted, koordinater avrundet til 2 desimaler | GDPR 6(1)(f), tilby lokale rapporter | Offentlig API, andre besøkende, Webhuset | 7 dager |
| Misbruksvern for rapporter | HMAC av IP og User-Agent | GDPR 6(1)(f), sikkerhet og spamvern | Webhuset/databaseadministrator | Maksimalt 60 minutter |
| Badetemperatur til Yr | Badeplass, temperatur, tidspunkt, eksakte koordinater | GDPR 6(1)(b), levere uttrykkelig forespurt innsending | Yr/MET, Webhuset | Lokal leveringslogg: 30 dager |
| Teknisk drift | IP kan finnes i leverandørens tilgangs-/sikkerhetslogger | GDPR 6(1)(f), sikker og stabil drift | Webhuset og relevante API-leverandører | Følger verifisert leverandøravtale |

Værvakt setter ikke informasjonskapsler, bruker ikke annonseverktøy og lager
ikke individuelle besøksprofiler. Tidligere `site_visits`-data slettes ved
første kall til det deaktiverte sporingsendepunktet. Frontend fjerner tidligere
Værvakt-lagring, service worker-registreringer og Værvakt-cache.

## Interesseavveiing for lokale rapporter

Formålet er å vise ferske, lokale værobservasjoner og hindre automatisert spam.
Behandlingen er nødvendig fordi rapportene må knyttes til et geografisk område,
og et kortvarig klientskille trengs for å håndheve en praktisk rate limit.

Brukeren velger selv å publisere rapporten og informeres før innsending.
Risikoen reduseres ved at:

- visningsnavn er valgfritt og brukeren oppfordres til å bruke kallenavn;
- koordinater avrundes til omtrent én kilometer og utleveres ikke i API-et;
- rapporter slettes etter 7 dager;
- sikkerhetshash lagres separat og slettes senest etter 60 minutter;
- det lagres ikke e-post, konto eller andre direkte identifikatorer;
- brukeren kan kreve retting eller sletting via kontaktpunktet.

Med disse tiltakene vurderes tjenestens interesse som forholdsmessig sammenlignet
med den begrensede behandlingen. Vurderingen skal gjentas dersom funksjonen,
datamengden eller mottakerne endres.

## Rutine for registrertes rettigheter

Ved en henvendelse brukes omtrent tidspunkt, sted og eventuelt visningsnavn til
å finne rapporten. Identitet skal ikke kreves utover det som er nødvendig for å
unngå at andre får slettet eller utlevert feil rapport. Henvendelser og tiltak
dokumenteres uten å lagre mer informasjon enn nødvendig.

## Operasjonelle kontroller

Før og etter større endringer skal eieren:

1. verifisere at `kontakt@værvakt.no` virker og overvåkes;
2. kontrollere databehandleravtale og slettetid for Webhusets tilgangslogger;
3. begrense database- og admin-tilgang til personer med tjenstlig behov;
4. kontrollere at automatiske slettinger faktisk kjører ved normal API-trafikk;
5. gjennomgå mottakere, lenker og personverntekst minst årlig;
6. dokumentere og håndtere eventuelle avvik etter gjeldende rutiner.

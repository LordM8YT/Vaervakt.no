# Push-varsler v1

Push skal ikke vises eller be om nettlesertillatelse før hele leveringskjeden er
testet. Produksjonen har VAPID-nøkler, og service workeren kan vise et mottatt
varsel, men abonnementslagring, senderjobb og brukergrensesnitt mangler fortsatt.

## Første varseltyper

V1 lanseres med to enkle, forutsigbare MET-prognosevarsler:

1. **Frost:** laveste prognosetemperatur er `0,0 °C` eller lavere innen 12 timer.
2. **Sterk vind:** høyeste prognoserte vindstyrke er `15,0 m/s` eller mer innen
   12 timer.

Varslene skal omtales som prognosevarsler fra MET, ikke som offisielle
farevarsler. Torden, kraftig regn, snø, lokale rapporter og badetemperatur
kommer først etter at v1 har dokumentert god levering og akseptabelt støynivå.

Samme hendelse skal ikke sendes flere ganger:

- frost: maksimalt ett varsel per sted per 12 timer;
- sterk vind: maksimalt ett varsel per sted per 6 timer;
- varsel sendes på nytt først når terskelen har vært under grensen og senere
  krysses igjen.

## Samtykke og brukeropplevelse

- Ingen tillatelsesdialog ved første besøk eller sideinnlasting.
- Brukeren åpner selv «Varsler» for det valgte stedet.
- Før nettleserens dialog vises, forklarer Værvakt hvilke varseltyper som er
  valgt, hvilket sted som brukes, at varsler kan slås av igjen, og at
  nettleseren lagrer et teknisk abonnement.
- Nettlesertillatelse forespørres først etter at brukeren trykker
  «Slå på varsler».
- Avslag skal gi en forklaring og lenke til nettleserinnstillinger, ikke nye
  gjentatte forespørsler.
- Det skal finnes én synlig handling for «Slå av varsler» som både avslutter
  abonnementet i nettleseren og sletter serverraden.

## Sted og dataminimering

V1 støtter ett varslingssted per nettleserabonnement. Valgt sted vises med navn,
mens koordinater avrundes til to desimaler før lagring på serveren. Det tilsvarer
omtrent én kilometers presisjon og er nok til punktprognosen.

Serveren lagrer bare:

- push-endepunkt, `p256dh` og `auth` fra nettleserabonnementet;
- avrundet bredde-/lengdegrad og offentlig stedsnavn;
- valgte varseltyper;
- tidspunkt for oppretting, siste vellykkede levering og siste sendte hendelse.

Ugyldige abonnementer slettes når push-leverandøren svarer `404` eller `410`.
Abonnementer uten vellykket levering eller kontakt i 90 dager slettes
automatisk.

## Teknisk leveringsgate

Følgende må være grønt før funksjonen aktiveres i grensesnittet:

- [x] Offentlig og privat VAPID-nøkkel samt subject er konfigurert i produksjon.
- [x] Service worker har `push`- og `notificationclick`-håndtering.
- [x] Skjult subscribe/update/unsubscribe-endepunkt med feltvalidering, rate
  limiting, avrundede koordinater og 90 dagers slettetid.
- [ ] Kryptert push-sender og planlagt prognosejobb.
- [ ] Service worker registreres først etter uttrykkelig samtykke.
- [ ] Leveringstest i Chromium desktop og installert Android-PWA.
- [ ] Klikk åpner riktig sted og varseltype i appen.
- [ ] `404`/`410`, duplikatvarsler, avslag og avregistrering er testet.

Inntil alle åpne punkter er lukket, skal appen fortsette å avregistrere eldre
service workers og ikke vise en push-bryter.

Endepunktet er `POST /api/push.php`. `action` kan være `subscribe`, `update`
eller `unsubscribe`. Det skal ikke kalles fra frontend før samtykkeflyten og
senderjobben er ferdig.

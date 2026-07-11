# Plan: Enkel tilkobling av værstasjoner til Værvakt.no

Dette dokumentet er en intern plan for hvordan vi kan gjøre tilkobling av private værstasjoner så enkel som mulig for vanlige brukere.

## Målet

Brukeren skal i beste fall bare måtte:

1. Åpne **Koble til værstasjon** på Værvakt.no.
2. Velge produsent eller tjeneste.
3. Logge inn hos leverandøren.
4. Godkjenne tilgang.
5. Bekrefte stasjonen som Værvakt finner automatisk.

Deretter skal Værvakt hente målinger automatisk uten at brukeren må forstå API, JSON, URL-er eller serveroppsett.

## Foreslått brukerflyt

Lag en side som for eksempel:

```text
/koble-til-stasjon
```

Siden kan ha valg som:

- Koble til Netatmo
- Koble til WeatherLink
- Koble til Ecowitt
- Koble til Home Assistant
- Koble til CumulusMX / WeeWX
- Annen værstasjon

Etter vellykket tilkobling kan Værvakt vise:

```text
Vi fant værstasjonen:

Navn: Patrick sin værstasjon
Sted: Drammen
Temperatur: 17,8 °C
Luftfuktighet: 74 %
Siste måling: Nå

[ Bekreft og aktiver ]
```

## Integrasjoner

### Netatmo

Beste brukeropplevelse er OAuth:

- Brukeren trykker **Koble til Netatmo**.
- Brukeren logger inn hos Netatmo.
- Brukeren godkjenner at Værvakt får lese målingene.
- Værvakt mottar et tilgangstoken.
- Værvakt finner tilgjengelige stasjoner og moduler automatisk.
- Brukeren velger hvilken stasjon som skal publiseres.

### WeatherLink / Davis

Samme prinsipp som Netatmo:

- Brukeren kobler WeatherLink-kontoen sin til Værvakt.
- Værvakt finner stasjonene på kontoen.
- Brukeren velger stasjon.
- Data synkroniseres automatisk.

### Ecowitt

Mange Ecowitt-gatewayer støtter en egendefinert værserver.

En enkel løsning:

- Brukeren oppretter en stasjon på Værvakt.
- Værvakt genererer stasjons-ID og hemmelig nøkkel.
- Værvakt viser ferdig serveradresse og sti.
- Brukeren kopierer dette inn i Ecowitt-appen under **Custom Weather Server**.

Eksempel:

```text
Server: værvakt.no
Port: 443
Protokoll: HTTPS
Path: /api/station-upload.php?station=123&key=HEMMELIG_NØKKEL
Intervall: 300 sekunder
```

Vi kan senere undersøke om oppsettet kan forenkles med QR-kode eller dyp-lenke inn i appen.

### Home Assistant

Mulige løsninger:

- En offisiell Værvakt-integrasjon.
- En ferdig blueprint eller automation.
- En webhook der brukeren bare limer inn en generert URL.

### CumulusMX og WeeWX

For brukere som allerede kjører værstasjonsprogramvare på PC eller Raspberry Pi:

- Værvakt genererer en ferdig opplastingsadresse.
- Programvaren sender data hvert femte minutt.

### Universelt JSON-API

For egenbygde stasjoner, Raspberry Pi, ESP32 og andre systemer bør vi tilby et enkelt API.

```http
POST /api/v1/stations/observations
Authorization: Bearer STASJONSNØKKEL
Content-Type: application/json
```

```json
{
  "station_id": 123,
  "observed_at": "2026-07-11T11:30:00+02:00",
  "temperature_c": 18.7,
  "humidity_percent": 71,
  "pressure_hpa": 1012.4,
  "wind_speed_ms": 3.8,
  "wind_gust_ms": 7.1,
  "wind_direction_deg": 225,
  "rain_mm_hour": 0.6
}
```

## Hva som bør bygges først

1. Databasetabeller for stasjoner, integrasjoner og observasjoner.
2. Universelt JSON-API.
3. Ecowitt Custom Weather Server.
4. CumulusMX / WeeWX-opplasting.
5. Home Assistant-webhook.
6. Netatmo OAuth.
7. WeatherLink-integrasjon.
8. Eventuelt et lite program kalt **Værvakt Connector**.

## Foreslått databaseoppsett

### `weather_stations`

- `id`
- `user_id`
- `name`
- `location`
- `latitude`
- `longitude`
- `provider`
- `provider_station_id`
- `public`
- `status`
- `last_seen_at`
- `created_at`

### `weather_station_credentials`

- `id`
- `station_id`
- `provider`
- `access_token_encrypted`
- `refresh_token_encrypted`
- `expires_at`
- `created_at`
- `updated_at`

Denne tabellen må aldri eksponeres direkte til frontend.

### `weather_observations`

- `id`
- `station_id`
- `observed_at`
- `received_at`
- `temperature_c`
- `humidity_percent`
- `pressure_hpa`
- `wind_speed_ms`
- `wind_gust_ms`
- `wind_direction_deg`
- `rain_mm_hour`
- `rain_mm_day`
- `raw_payload`

## Sikkerhet

- Alle stasjoner må ha en unik nøkkel.
- Hemmelige nøkler må aldri vises i offentlig feed eller JavaScript.
- OAuth-token må lagres kryptert.
- Brukeren må kunne regenerere stasjonsnøkkelen.
- API-et må ha rate limiting.
- Inndata må valideres server-side.
- Vi må lagre både måletidspunkt og mottakstidspunkt.
- Gamle eller urealistiske målinger må kunne avvises eller merkes.
- En stasjon bør merkes som frakoblet etter for eksempel 15–30 minutter uten nye data.

## God brukeropplevelse

Tilkoblingsveiviseren bør vise tydelig status:

```text
1. Leverandør valgt
2. Konto tilkoblet
3. Stasjon funnet
4. Første måling mottatt
5. Stasjonen er aktiv
```

Det bør også finnes en knapp for **Test tilkobling**.

På profilsiden kan brukeren se:

- Om stasjonen er online.
- Når siste måling kom inn.
- Hvilke sensorer som er oppdaget.
- Om stasjonen er offentlig.
- Mulighet for å koble fra eller regenerere nøkkel.

## Værvakt Connector

For stasjoner uten moderne API kan vi senere lage et lite koblingsprogram.

Programmet kan:

- Logge brukeren inn på Værvakt.
- Oppdage CumulusMX, WeeWX eller Home Assistant lokalt.
- Finne tilgjengelige sensorer.
- Sende målingene automatisk.
- Oppdatere seg selv.

## Oppsummering

Den beste langsiktige løsningen er:

> Velg produsent → logg inn → godkjenn → velg stasjon → ferdig.

For leverandører uten innloggings-API bruker vi:

> Opprett stasjon → kopier ferdig URL eller skann QR-kode → test tilkobling → ferdig.

Målet er at brukeren aldri skal måtte skrive JSON eller forstå hvordan API-er fungerer. Hvis en bruker må ofre en geit til routeren for å få værdata inn, har vi gjort det for komplisert.

## Viktig om hemmeligheter

API-nøkler, stasjonsnøkler, OAuth-hemmeligheter og tokens skal ikke legges i dette dokumentet eller i offentlig GitHub-kode. Bruk miljøvariabler eller privat serverkonfigurasjon.

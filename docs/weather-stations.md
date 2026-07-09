# Værvakt værstasjoner

Denne integrasjonen lar private værstasjoner sende automatiske målinger til Værvakt uten at de blandes med manuelle brukerrapporter.

## Flyt

1. Gå til `/admin/?view=stations`.
2. Opprett en ny stasjon.
3. Kopier `Station ID` og API-nøkkelen som vises etter opprettelse.
4. Konfigurer værstasjonen, Home Assistant, Node-RED, Netatmo-jobb eller annen bridge til å poste JSON til Værvakt.
5. Godkjente stasjoner vises offentlig via `GET /api/stations.php`.

API-nøkkelen vises bare når den lages eller regenereres. Hvis den mistes, lag en ny i adminpanelet.

## Innsending

`POST https://værvakt.no/api/stations.php?action=submit`

Send `stationId` og `apiKey` enten i JSON-body eller som headere.

Header-variant:

```http
X-Vaervakt-Station-Id: vvst_123456abcdef
X-Vaervakt-Station-Key: vvs_...
Content-Type: application/json
```

Body:

```json
{
  "temperature": 18.7,
  "humidity": 72,
  "pressure": 1012.4,
  "rainRate": 0,
  "rainTotal": 1.2,
  "windSpeed": 2.4,
  "windDirection": 225,
  "observedAt": "2026-07-09T12:30:00+02:00"
}
```

Minstekravet er minst én måleverdi. `temperature` er anbefalt.

## Felt

| Felt | Enhet | Påkrevd | Notat |
| --- | --- | --- | --- |
| `temperature` | °C | Nei | Akseptert område: -60 til 60 |
| `humidity` | % | Nei | 0 til 100 |
| `pressure` | hPa | Nei | 800 til 1100 |
| `rainRate` | mm/t | Nei | Øyeblikksregn |
| `rainTotal` | mm | Nei | Akkumulert regn, gjerne siste døgn |
| `windSpeed` | m/s | Nei | 0 til 100 |
| `windDirection` | grader | Nei | 0 til 360 |
| `observedAt` | ISO-8601 | Nei | Hvis tom brukes mottakstidspunkt |

## Lese stasjoner

`GET https://værvakt.no/api/stations.php`

Eksempel med område:

```txt
GET /api/stations.php?lat=58.15&lon=7.95&radiusKm=25
```

Responsen inneholder bare godkjente stasjoner. Koordinater kan være eksakte, avrundet til område eller skjult, avhengig av personvernvalget i admin.

## Home Assistant-eksempel

```yaml
rest_command:
  vaervakt_station_submit:
    url: "https://værvakt.no/api/stations.php?action=submit"
    method: post
    headers:
      X-Vaervakt-Station-Id: "vvst_123456abcdef"
      X-Vaervakt-Station-Key: "vvs_..."
      Content-Type: "application/json"
    payload: >
      {
        "temperature": {{ states('sensor.outdoor_temperature') | float }},
        "humidity": {{ states('sensor.outdoor_humidity') | float }},
        "pressure": {{ states('sensor.pressure') | float }},
        "observedAt": "{{ now().isoformat() }}"
      }
```

## Personvern

Ikke bruk nøyaktig hjemmeadresse offentlig med mindre eieren faktisk ønsker det. Standardvalget i admin er `Vis område cirka`, som runder av koordinatene i offentlig API.


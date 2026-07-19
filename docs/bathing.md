# Badeplassøk og innsending til Yr

Værvakt eksponerer ikke Yr-nøkkelen i frontend. Badeplassøk og validering går via PHP-backend.

## Søk

```http
GET /api/bath-locations.php?q=opera
```

Søket krever minst to tegn. Serveren:

1. søker hos Yr;
2. filtrerer bort treff som ikke har kategorien `Badeplass`;
3. validerer `locationId`, navn og koordinater;
4. cacher søket i ti minutter;
5. returnerer maksimalt åtte treff.

Eksempel:

```json
{
  "success": true,
  "locations": [
    {
      "locationId": "0-10249",
      "name": "Operastranda",
      "regionName": "Oslo, Oslo",
      "categoryName": "Badeplass",
      "lat": 59.90649,
      "lon": 10.75251
    }
  ],
  "count": 1,
  "source": "Yr"
}
```

## Innsending

```http
POST /api/bath-reports.php
Content-Type: application/json
```

```json
{
  "locationId": "0-10249",
  "temperature": 19.5,
  "heatedWater": false
}
```

Backend henter badeplassen på nytt fra Yr og bruker Yr sitt navn og sine koordinater. Eventuelle klientverdier for navn eller koordinater er ikke autoritative. Innsending uten en gyldig, bekreftet Yr-ID avvises med en tydelig feilmelding.

`yr_location_id` lagres sammen med den tekniske leveringsloggen. Loggen følger `BATH_REPORT_RETENTION_DAYS`; innsendernavn lagres ikke.

## Misbruksvern

Før backend gjør detaljoppslaget hos Yr, begrenses innsendinger med et kortvarig,
pseudonymt HMAC-hash av IP-adresse og User-Agent. Rå IP-adresse lagres ikke i
rate-limit-tabellen.

Standardgrensen er fem forsøk per 30 minutter. Den kan justeres med:

```text
BATH_RATE_LIMIT=5
BATH_RATE_WINDOW_MINUTES=30
```

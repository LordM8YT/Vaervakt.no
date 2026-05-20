# Værvakt.no

En progressiv webapp (PWA) for lokale værobservasjoner, kart, push-varsler og offline-kø. Appen kombinerer MET.no sin prognose med rapporter fra brukere i nærheten.

## 🚀 Setup

### 1. Environment-variabler

Kopier `.env.example` til `.env` og fyll inn:

```bash
cp .env.example .env
```

```
DB_HOST=your_host
DB_NAME=your_database
DB_USER=your_user
DB_PASS=your_password

# Push (støtter både *_KEY og korte navn)
VAPID_SUBJECT=mailto:deg@example.com
VAPID_PUBLIC_KEY=din_offentlige_push_nokkel
VAPID_PRIVATE_KEY=din_private_push_nokkel

# Valgfritt: støtteknapp i appen
SUPPORT_URL=https://din-vipps-payment-link
SUPPORT_LABEL=Støtt med Vipps
```

### 2. Arkitektur i korte trekk

- `index.php` – hovedapp med UI, MET.no-prognose, kart, søk, feed, PWA-hooks og klientlogikk
- `save.php` – lagrer rapporter, enkel antispam/rate limiting og JSON-respons for AJAX
- `db.php` / `config.php` – database og miljøvariabler
- `reports_nearby.php` / `search.php` – kart- og søk-endepunkter
- `subscriptions.php` / `send_push.php` – push-abonnementer og utsending
- `service-worker.js` / `manifest.json` – PWA, caching og installasjon

### 3. Database-setup

Kjør SQL-skriptet for å opprette tabeller:

```bash
mysql -u your_user -p your_database < schema.sql
```

**Migrasjons-filer:** Ligger i `migrations/` mappen for historisk referanse.

### 4. Service Worker & PWA

- **Manifest:** `manifest.json` – PWA-konfigurasjon
- **Service Worker:** `service-worker.js` – Offline-support og caching

## 📱 Bruk

### Viktige flyter

- Send værrapport med navn, temperatur, værtype og automatisk eller manuelt sted
- Bruk `Bruk min posisjon` for GPS + reverse geocoding via Nominatim
- Se rapporter i liste og på Leaflet-kart
- Få køhåndtering ved offline innsending
- Installer appen som PWA
- Aktiver push-varsler når VAPID er satt
- Vis støtteknapp automatisk når `SUPPORT_URL` er satt

### API-Integrasjoner

#### MET.no Værdata
Hentes automatisk fra MET.no sitt API. Har fallback til cache hvis API-en er nede.

```
GET https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=58.1504&lon=7.9470
```


### Database-støtte

Appen støtter begge disse tabellvariantene:

- `weather_reports`
- eldre `reports`

UI og lagring mapper automatisk mellom skjemaene.

### Legg til koordinater (migrasjon)
Hvis du ønsker å lagre GPS-posisjon for hver rapport, kjør migrasjonen i `migrations/migrasjon_add_latlon.sql` eller disse SQL-kommandoene mot databasen:

```sql
ALTER TABLE `weather_reports` ADD COLUMN `latitude` DECIMAL(10,6) NULL AFTER `location`;
ALTER TABLE `weather_reports` ADD COLUMN `longitude` DECIMAL(10,6) NULL AFTER `latitude`;

-- For eldre `reports`-tabell:
ALTER TABLE `reports` ADD COLUMN `latitude` DECIMAL(10,6) NULL;
ALTER TABLE `reports` ADD COLUMN `longitude` DECIMAL(10,6) NULL;
```

Husk å ta backup (f.eks. `mysqldump`) før du kjører migrasjonen. Appen støtter nå valgfri `lat`/`lon` i skjema-innsending og vil plotte rapporter som har koordinater.


## 🔧 API-Endepunkter

### POST `/save.php`
Lagrer værobservasjon.

Forventede felt:

- `user`
- `weather_type`
- `loc`
- `temp`
- valgfritt `lat`
- valgfritt `lon`

AJAX-klienter får JSON tilbake med `success` og den lagrede rapporten.

## ⚡ Performance, Push og Offline

### Automatiske backup-skript

Jeg la ved to enkle skript du kan bruke fra prosjektroten:

- `backup_db.sh` — Linux/macOS (kan lese `.env` for DB-verdier)
- `backup_db.ps1` — Windows PowerShell

Eksempel (Linux):

```bash
./backup_db.sh
```

Eksempel (PowerShell):

```powershell
.\backup_db.ps1
```

Disse skriptene skriver en timestampet SQL-dump i arbeidsmappen.

### Push-varsler

Push-knappen aktiveres bare når VAPID-nøkler finnes i `.env`.

`send_push.php` sender varsel til alle abonnenter:

- `send_push.php` — CLI‑skript som bruker `minishlink/web-push`. Installer avhengigheter med:

```bash
composer install
```

Kjør skriptet slik:

```bash
php send_push.php --title "Testvarsel" --body "Dette er en test" --url "https://vaarvakt.no"
```

Skriptet fjerner også abonnementer som gir 404/410.

### Service Worker

Service workeren bruker:

- **HTML-navigasjon:** Network-first (frisk data), fallback til cache
- **API-kall (MET.no):** cache-first
- **Kjernefiler:** precache av app-shell

### Offline-rapportering

Hvis `save.php` ikke nås på grunn av nettverksfeil, lagres rapporten i IndexedDB og sendes senere automatisk.

## 🐛 Debugging

### Service Worker
- Åpne DevTools → Application → Service Workers
- Sjekk "Update on reload" for testing

### API-kall
- Åpne DevTools → Network
- Sjekk MET.no-responser
- Se om cache-status er `from cache` eller `from network`

### Feil-logging

- Sjekk PHP error log (ofte `error_log` i web root)
- `save.php` logger database- og abonnementsfeil
- nettleserfeil sees i DevTools Console / Network

## 📁 Filstruktur

```
.
├── index.php                 # Hovedside
├── save.php                 # Lagring + antispam/rate limit
├── service-worker.js        # PWA Service Worker
├── db.php                  # Database-tilkobling
├── config.php              # Konfigurasjon (env-loader)
├── reports_nearby.php      # Rapporter nær koordinater
├── search.php              # Stedsøk
├── manifest.json           # PWA manifest
├── .env                    # Environment-variabler (SECRET)
├── .env.example            # Template for .env
├── schema.sql              # Database-schema
├── migrations/             # Gamle SQL-migrasjoner
│   ├── migrasjon_reporter_name.sql
│   ├── migrasjon_værtekst.sql
│   └── schema_nuke_og_gjenopprett.sql
└── icons/                  # PWA ikoner
```

## 🔐 Sikkerhet

✅ **Implementert:**
- Input-validering på alle forms
- SQL-preparedstatements (PDO)
- HTMLspecialchars() for output-encoding
- miljøvariabler i `.env`
- enkel honeypot i skjemaet
- enkel rate limiting i `save.php`
- tidsjekk på innsending for å luke bort åpenbar bot-trafikk

⚠️ **Anbefaling:**
- Sikre `.env`-filen på hosting (chmod 600 / remove web access)
- Bruk HTTPS i produksjon
- Monitor logging for misbruk

## 📊 Vedlikehold

**Cache-invalidering:** Bump `CACHE_VERSION` i `service-worker.js`

**Database-backup:**
```bash
mysqldump -u user -p database > backup.sql
```

**Testing:**
- Åpne DevTools og deaktiver nettverkstilkoblingen
- Siden skal fortsatt fungere offline (HTML + cached data)
- Appen skal fortsatt fungere offline for cached HTML og API-data

## 🚀 Automatisk deploy med GitHub Actions

Repoet er nå klargjort for automatisk deploy til Webhuset via GitHub Actions og `rsync` over SSH.

Filer som styrer deploy:

- `.github/workflows/deploy.yml`
- `.github/rsync-exclude.txt`
- `.github/workflows/release.yml`
- `.github/release-package-exclude.txt`

Workflowen kjører automatisk når du pusher til `main`, og kan også startes manuelt fra GitHub under `Actions`.

### GitHub Secrets du må legge inn

Legg disse inn under `Settings -> Secrets and variables -> Actions` i GitHub-repoet:

- `WEBHUSET_HOST` - f.eks. `ssh.dittdomene.no` eller hosten Webhuset oppgir for SSH
- `WEBHUSET_PORT` - valgfritt, bruk `22` hvis du ikke har en egen port
- `WEBHUSET_USERNAME` - SSH-brukernavn hos Webhuset
- `WEBHUSET_REMOTE_PATH` - absolutt sti til webroten som skal oppdateres
- `WEBHUSET_SSH_KEY` - valgfritt, privat SSH-nokkel som matcher en public key du har lagt inn hos Webhuset
- `WEBHUSET_PASSWORD` - valgfritt, SSH-passord hvis du ikke bruker nøkkel enda
- `WEBHUSET_KNOWN_HOSTS` - valgfritt, anbefalt. Lim inn linjen fra `ssh-keyscan -H <host>`

Workflowen krever enten `WEBHUSET_SSH_KEY` eller `WEBHUSET_PASSWORD`.

### Hvordan deployen virker

1. GitHub sjekker ut repoet.
2. Composer-avhengigheter installeres i workflowen.
3. Workflowen kobler seg til Webhuset via SSH.
4. Filer synkes med `rsync`.
5. Sensitive og lokale filer som `.env`, `.git`, backups og migrasjoner holdes utenfor deploy via `.github/rsync-exclude.txt`.

Hvis du starter med passord og senere vil stramme opp sikkerheten, kan du bytte til SSH-nøkkel uten å endre resten av workflowen.

### Viktig om `.env`

`.env` blir ikke lastet opp fra GitHub. Produksjonsverdiene skal fortsatt ligge kun på serveren.

### Første oppsett

1. Legg prosjektet i et GitHub-repo.
2. Legg inn GitHub Secrets-listen over.
3. Bekreft at `WEBHUSET_REMOTE_PATH` peker til riktig mappe.
4. Push til `main`.
5. Sjekk `Actions`-fanen i GitHub for første deploy.

## 📦 GitHub Releases

Repoet er også satt opp med automatiske GitHub Releases.

Når du pusher en tag som `v1.0.0`, skjer dette automatisk:

1. GitHub bygger appen med produksjonsavhengigheter.
2. Det lages en ZIP-pakke som er trygg å dele videre.
3. Det opprettes en GitHub Release med auto-genererte release notes.
4. Release-siden får både ZIP-fil og `sha256`-checksum som vedlegg.

Dette gjør det enklere for andre å:

- laste ned siste stabile versjon
- hente en deploy-klar pakke uten `.env`
- se en tydelig historikk over versjoner

### Lage en ny release

Kjør fra repoet lokalt:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Bytt `v1.0.0` til neste versjon, for eksempel `v1.0.1` eller `v1.1.0`.

### Hva som lastes ned

- GitHub sin innebygde `Source code (zip)` er best for utviklere som vil jobbe videre i repoet.
- `vaervakt-no-vX.Y.Z.zip`-asseten er best når noen bare vil hente siste appversjon raskt.

## 🔔 Push-varsler og VAPID

For å aktivere push-varsler må du legge inn et VAPID-nøkkelpar i `.env`. Appen støtter både:

- `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`
- `VAPID_PUBLIC` / `VAPID_PRIVATE`

En rask måte med `web-push` (Node.js):

```bash
npx web-push generate-vapid-keys --json
```

Kopier `publicKey` og `privateKey` inn i `.env` som:

```
VAPID_SUBJECT=mailto:deg@example.com
VAPID_PUBLIC_KEY=BN...yourpublickey...
VAPID_PRIVATE_KEY=yourprivatekey...
```

Når VAPID er satt vil brukere kunne klikke `Aktiver varsler` i appen for å abonnere. Serveren lagrer abonnementet i `subscriptions`-tabellen.

## 📴 Offline-rapportering (kø)

Appen støtter nå offline-innsending: når du sender en rapport uten nett, lagres den lokalt (IndexedDB) og sendes automatisk når nettverk er tilbake. Test:

1. Åpne appen i nettleseren.
2. Slå av nettverk i DevTools (Network -> Offline).
3. Send en rapport via skjemaet — du får en beskjed om at rapporten ble lagret i kø.
4. Slå på nettverket igjen; appen prøver å sende køen automatisk.

## 🧭 Overtakelse for ekstern utvikler

Det viktigste å vite ved overtakelse:

- hovedlogikken ligger i `index.php`, og klientkoden er foreløpig inline i samme fil
- rapportlagring skjer i `save.php`
- appen må tåle både `weather_reports` og eldre `reports`
- støtteknappen styres kun av `SUPPORT_URL`
- push styres kun av VAPID-variabler i `.env`

Hvis appen skal videreutvikles mye, er neste naturlige steg å splitte ut:

1. JS fra `index.php` til egne filer
2. CSS-regler til egen stylesheet
3. PHP-hjelpefunksjoner til en egen `app/` eller `includes/`-mappe


## 🤝 Kontakt

Patrick – lordm8yt@gmail.com

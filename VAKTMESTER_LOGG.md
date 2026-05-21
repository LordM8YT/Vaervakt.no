# VAKTMESTER_LOGG

## fix/push-notifications-v2

Dato: 2026-05-21

## Kartlegging

- Gikk gjennom `index.php` for service worker-registrering, push-abonnement, VAPID-konvertering og click-listener på push-knappen.
- Gikk gjennom `reports_nearby.php`. Den er et rent JSON-endepunkt for nærliggende rapporter og inneholder ingen service worker-registrering, push-logikk eller browser event-listeners å fjerne.

## Endringer i `index.php`

- Erstattet direkte `navigator.serviceWorker.register(...)` med `getServiceWorkerRegistration()`.
  - Funksjonen memoizer én registrerings-promise.
  - Ved feil nullstilles promise slik at ny retry er mulig.
  - All push-kode bruker samme registrering i stedet for parallelle registreringsløp.
- Konsoliderte push-UI i `setPushUiState()`.
  - Samme funksjon setter tekst, disabled-state og CSS-klasser for aktiv, klar, blokkert, busy og unsupported.
- Oppdaterte `syncPushUi()`.
  - Bruker samme service worker-registrering som subscribe-flyten.
  - Sjekker eksisterende subscription først.
  - Leser notification permission der nettleseren støtter det.
- Strippet overlappende push-listener-mønster.
  - Push-knappen bruker nå én `onclick`-binding i stedet for å akkumulere `addEventListener('click', ...)` ved ny init.
- Herdet `urlBase64ToUint8Array()` for VAPID.
  - Trimmer input.
  - Fjerner whitespace.
  - Validerer ugyldig base64url-lengde.
  - Legger på korrekt padding.
  - Konverterer base64url (`-` og `_`) til vanlig base64 før `atob()`.
  - Returnerer `Uint8Array.from(...)` direkte.
- La inn eksplisitt permission-sjekk før subscribe.
  - `getPushPermissionState()` bruker `registration.pushManager.permissionState(subscribeOptions)` når tilgjengelig.
  - `ensurePushPermission()` håndterer `prompt/default`, `denied` og `granted` før `pushManager.subscribe(...)` kalles.
- La inn `pushSubscriptionInFlight` som lås rundt subscribe-flyten.
  - Ekstra klikk mens subscribe pågår returnerer samme promise i stedet for å starte et nytt subscribe-kall.

## Hvorfor dette stopper double-fire AbortError

Tidligere kunne flere click-handlers eller raske dobbeltklikk starte flere parallelle `pushManager.subscribe(...)`-kall mot samme service worker/push manager. Nettlesere kan avbryte ett av disse løpene med `AbortError`, spesielt når permission prompt, service worker readiness og subscription-opprettelse konkurrerer.

Ny flyt har tre sikringer:

1. Service worker-registrering er memoizet i `getServiceWorkerRegistration()`, så siden starter ikke flere registreringsløp for samme scope.
2. Push-knappen får én konsolidert handler via `onclick`, ikke overlappende `addEventListener`-bindinger.
3. `pushSubscriptionInFlight` gjør subscribe-flyten idempotent mens den pågår. Hvis brukeren trykker to ganger, returneres eksisterende promise og `subscribe()` kalles ikke på nytt.

Resultatet er at permission-sjekk og VAPID-konvertering skjer én gang per forsøk, og nettleseren får bare ett aktivt `pushManager.subscribe(...)`-kall. Det er dette som fjerner double-fire abort-kilden.

## Verifisering

- Inline JavaScript i `index.php` sjekket med `node --check` etter enkel PHP-placeholder-substitusjon.
- `git diff --check` kjørt uten whitespace-feil.
- PHP CLI er ikke installert i arbeidsmiljøet, så `php -l` kunne ikke kjøres lokalt her.

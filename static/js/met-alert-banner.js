(() => {
  'use strict';

  const API_URL = '/api/met-alerts.php';
  const REFRESH_MS = 5 * 60 * 1000;
  const DISMISSED_PREFIX = 'vv_met_alert_dismissed_';
  let lastPositionKey = '';

  function asCoordinate(value, min, max) {
    const number = Number(value);
    return Number.isFinite(number) && number >= min && number <= max ? number : null;
  }

  function findCoordinatesInObject(value, depth = 0) {
    if (!value || typeof value !== 'object' || depth > 5) return null;

    const lat = asCoordinate(value.lat ?? value.latitude, -90, 90);
    const lon = asCoordinate(value.lon ?? value.lng ?? value.longitude, -180, 180);
    if (lat !== null && lon !== null) {
      return { lat, lon };
    }

    for (const child of Object.values(value)) {
      const found = findCoordinatesInObject(child, depth + 1);
      if (found) return found;
    }

    return null;
  }

  function coordinatesFromStorage() {
    const preferredKeys = [
      'vaervakt_selected_location',
      'vaervakt_location',
      'selectedLocation',
      'weatherLocation',
      'vv_location',
    ];

    const keys = [
      ...preferredKeys.filter((key) => localStorage.getItem(key) !== null),
      ...Object.keys(localStorage).filter((key) => !preferredKeys.includes(key)),
    ];

    for (const key of keys) {
      const raw = localStorage.getItem(key);
      if (!raw || raw.length > 100000) continue;

      try {
        const parsed = JSON.parse(raw);
        const found = findCoordinatesInObject(parsed);
        if (found) return found;
      } catch {
        // Ikke alle localStorage-verdier er JSON.
      }
    }

    return null;
  }

  async function coordinatesFromGrantedGps() {
    if (!navigator.geolocation || !navigator.permissions?.query) return null;

    try {
      const permission = await navigator.permissions.query({ name: 'geolocation' });
      if (permission.state !== 'granted') return null;

      return await new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
          (position) => resolve({
            lat: position.coords.latitude,
            lon: position.coords.longitude,
          }),
          () => resolve(null),
          { enableHighAccuracy: false, timeout: 5000, maximumAge: 300000 },
        );
      });
    } catch {
      return null;
    }
  }

  async function getCoordinates() {
    return coordinatesFromStorage() || await coordinatesFromGrantedGps();
  }

  function isDismissed(alert) {
    try {
      return sessionStorage.getItem(DISMISSED_PREFIX + alert.id) === '1';
    } catch {
      return false;
    }
  }

  function dismiss(alert, element) {
    try {
      sessionStorage.setItem(DISMISSED_PREFIX + alert.id, '1');
    } catch {
      // Banneret kan fortsatt lukkes selv om storage er blokkert.
    }
    element.remove();
  }

  function ensureStyles() {
    if (document.getElementById('vv-met-alert-style')) return;

    const style = document.createElement('style');
    style.id = 'vv-met-alert-style';
    style.textContent = `
      #vv-met-alerts {
        position: relative;
        z-index: 2147483000;
        width: 100%;
        font-family: inherit;
      }
      .vv-met-alert {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 12px;
        align-items: start;
        padding: 12px 16px;
        color: #fff;
        background: linear-gradient(135deg, #92400e, #c2410c);
        border-bottom: 1px solid rgba(255,255,255,.25);
        box-shadow: 0 8px 24px rgba(2,6,23,.22);
      }
      .vv-met-alert[data-severity="danger"] {
        background: linear-gradient(135deg, #7f1d1d, #dc2626);
      }
      .vv-met-alert[data-severity="notice"] {
        color: #111827;
        background: linear-gradient(135deg, #fde047, #f59e0b);
      }
      .vv-met-alert-icon {
        font-size: 1.35rem;
        line-height: 1.2;
      }
      .vv-met-alert-copy strong,
      .vv-met-alert-copy span {
        display: block;
      }
      .vv-met-alert-copy strong {
        font-size: .96rem;
      }
      .vv-met-alert-copy span {
        margin-top: 2px;
        font-size: .82rem;
        opacity: .92;
      }
      .vv-met-alert-close {
        border: 0;
        padding: 2px 6px;
        color: inherit;
        background: transparent;
        font-size: 1.25rem;
        cursor: pointer;
      }
      @media (max-width: 640px) {
        .vv-met-alert { padding: 10px 12px; gap: 9px; }
      }
    `;
    document.head.appendChild(style);
  }

  function render(alerts) {
    document.getElementById('vv-met-alerts')?.remove();

    const visible = alerts.filter((alert) => !isDismissed(alert));
    if (!visible.length) return;

    ensureStyles();
    const container = document.createElement('section');
    container.id = 'vv-met-alerts';
    container.setAttribute('aria-label', 'Farevarsler fra Meteorologisk institutt');

    visible.slice(0, 3).forEach((alert) => {
      const banner = document.createElement('article');
      banner.className = 'vv-met-alert';
      banner.dataset.severity = alert.severity || 'notice';

      const icon = document.createElement('span');
      icon.className = 'vv-met-alert-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = alert.icon || '⚠️';

      const copy = document.createElement('div');
      copy.className = 'vv-met-alert-copy';

      const title = document.createElement('strong');
      title.textContent = alert.title || alert.label || 'Farevarsel';

      const detail = document.createElement('span');
      detail.textContent = [alert.area, alert.description].filter(Boolean).join(' – ') || 'Varsel levert av Meteorologisk institutt.';

      copy.append(title, detail);

      const close = document.createElement('button');
      close.className = 'vv-met-alert-close';
      close.type = 'button';
      close.setAttribute('aria-label', 'Lukk farevarsel');
      close.textContent = '×';
      close.addEventListener('click', () => dismiss(alert, banner));

      banner.append(icon, copy, close);
      container.appendChild(banner);
    });

    document.body.prepend(container);
  }

  async function refresh() {
    const coordinates = await getCoordinates();
    if (!coordinates) return;

    const positionKey = `${coordinates.lat.toFixed(3)},${coordinates.lon.toFixed(3)}`;
    lastPositionKey = positionKey;

    try {
      const url = new URL(API_URL, window.location.origin);
      url.searchParams.set('lat', coordinates.lat.toFixed(5));
      url.searchParams.set('lon', coordinates.lon.toFixed(5));

      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (!response.ok) return;

      const payload = await response.json();
      render(Array.isArray(payload.alerts) ? payload.alerts : []);
    } catch (error) {
      console.warn('Værvakt: kunne ikke hente farevarsler', error);
    }
  }

  window.addEventListener('storage', () => {
    const coordinates = coordinatesFromStorage();
    if (!coordinates) return;
    const nextKey = `${coordinates.lat.toFixed(3)},${coordinates.lon.toFixed(3)}`;
    if (nextKey !== lastPositionKey) refresh();
  });

  window.addEventListener('vaervakt:location-changed', refresh);
  window.addEventListener('load', refresh, { once: true });
  window.setInterval(refresh, REFRESH_MS);
})();

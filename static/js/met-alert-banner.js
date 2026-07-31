(() => {
  'use strict';

  const MET_API_URL = '/api/met-alerts.php';
  const REFRESH_MS = 5 * 60 * 1000;
  const DISMISSED_PREFIX = 'vv_alert_dismissed_';
  const STATUS_MARKER = 'VÆRVAKT-STATUS';

  let metAlerts = [];
  let userAlerts = [];
  let lastPositionKey = '';

  function asCoordinate(value, min, max) {
    const number = Number(value);
    return Number.isFinite(number) && number >= min && number <= max ? number : null;
  }

  function findCoordinatesInObject(value, depth = 0) {
    if (!value || typeof value !== 'object' || depth > 5) return null;

    const lat = asCoordinate(value.lat ?? value.latitude, -90, 90);
    const lon = asCoordinate(value.lon ?? value.lng ?? value.longitude, -180, 180);
    if (lat !== null && lon !== null) return { lat, lon };

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
        const found = findCoordinatesInObject(JSON.parse(raw));
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

  function normalizeAlert(alert, source = 'user') {
    if (!alert || typeof alert !== 'object') return null;

    const title = String(alert.title || alert.label || '').trim();
    const description = String(alert.description || alert.message || '').trim();
    if (!title && !description) return null;

    const sourceName = source === 'met' ? 'met' : 'user';
    const idBase = String(alert.id || `${sourceName}-${title}-${description}`);
    const severity = ['danger', 'warning', 'notice', 'info'].includes(alert.severity)
      ? alert.severity
      : 'notice';

    return {
      id: idBase.replace(/[^a-zA-Z0-9_-]/g, '-').slice(0, 180),
      source: sourceName,
      title: title || 'Værvakt-varsel',
      description,
      instruction: String(alert.instruction || '').trim(),
      area: String(alert.area || '').trim(),
      icon: String(alert.icon || (sourceName === 'met' ? '⚠️' : '📢')),
      severity,
      priority: Number.isFinite(Number(alert.priority)) ? Number(alert.priority) : 0,
    };
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
      // Banneret kan fortsatt lukkes når lagring er blokkert.
    }

    element.remove();
    render();
  }

  function ensureStyles() {
    if (document.getElementById('vv-alert-style')) return;

    const style = document.createElement('style');
    style.id = 'vv-alert-style';
    style.textContent = `
      #vv-alerts {
        position: relative;
        z-index: 2147483000;
        width: 100%;
        padding-top: env(safe-area-inset-top, 0px);
        font-family: inherit;
      }
      .vv-alert {
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
      .vv-alert[data-severity="danger"] {
        background: linear-gradient(135deg, #7f1d1d, #dc2626);
      }
      .vv-alert[data-severity="warning"] {
        background: linear-gradient(135deg, #92400e, #c2410c);
      }
      .vv-alert[data-severity="notice"] {
        color: #111827;
        background: linear-gradient(135deg, #fde047, #f59e0b);
      }
      .vv-alert[data-severity="info"] {
        background: linear-gradient(135deg, #075985, #0284c7);
      }
      .vv-alert-icon {
        font-size: 1.35rem;
        line-height: 1.2;
      }
      .vv-alert-copy strong,
      .vv-alert-copy span {
        display: block;
      }
      .vv-alert-copy strong {
        font-size: .96rem;
      }
      .vv-alert-copy span {
        margin-top: 2px;
        font-size: .82rem;
        line-height: 1.4;
        opacity: .94;
      }
      .vv-alert-source {
        margin-left: 7px;
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        opacity: .78;
      }
      .vv-alert-close {
        border: 0;
        padding: 2px 6px;
        color: inherit;
        background: transparent;
        font: inherit;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
      }
      @media (max-width: 640px) {
        .vv-alert { padding: 10px 12px; gap: 9px; }
      }
    `;

    document.head.appendChild(style);
  }

  function render() {
    document.getElementById('vv-alerts')?.remove();

    const alerts = [...userAlerts, ...metAlerts]
      .filter(Boolean)
      .filter((alert) => !isDismissed(alert))
      .sort((a, b) => (b.priority || 0) - (a.priority || 0));

    if (!alerts.length) return;

    ensureStyles();

    const container = document.createElement('section');
    container.id = 'vv-alerts';
    container.setAttribute('aria-label', 'Varsler fra Værvakt og Meteorologisk institutt');

    alerts.slice(0, 3).forEach((alert) => {
      const banner = document.createElement('article');
      banner.className = 'vv-alert';
      banner.dataset.severity = alert.severity || 'notice';

      const icon = document.createElement('span');
      icon.className = 'vv-alert-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = alert.icon || '⚠️';

      const copy = document.createElement('div');
      copy.className = 'vv-alert-copy';

      const title = document.createElement('strong');
      title.textContent = alert.title || 'Varsel';

      const source = document.createElement('small');
      source.className = 'vv-alert-source';
      source.textContent = alert.source === 'met' ? 'MET' : 'Værvakt';
      title.appendChild(source);

      const detail = document.createElement('span');
      detail.textContent = [alert.area, alert.description, alert.instruction]
        .filter(Boolean)
        .join(' – ');

      copy.append(title);
      if (detail.textContent) copy.append(detail);

      const close = document.createElement('button');
      close.className = 'vv-alert-close';
      close.type = 'button';
      close.setAttribute('aria-label', 'Lukk varsel');
      close.textContent = '×';
      close.addEventListener('click', () => dismiss(alert, banner));

      banner.append(icon, copy, close);
      container.appendChild(banner);
    });

    document.body.prepend(container);
  }

  function showUserAlert(alert) {
    const normalized = normalizeAlert(alert, 'user');
    if (!normalized) return;

    userAlerts = [
      normalized,
      ...userAlerts.filter((item) => item.id !== normalized.id),
    ];

    render();
  }

  function removeUserAlert(id) {
    const normalizedId = String(id || '');
    userAlerts = userAlerts.filter((alert) => alert.id !== normalizedId);
    render();
  }

  function findLegacyStatusCard() {
    const candidates = [...document.querySelectorAll('article, section')];
    return candidates.find((element) =>
      String(element.textContent || '').toUpperCase().includes(STATUS_MARKER),
    ) || null;
  }

  function removeLegacyStatusCard() {
    const card = findLegacyStatusCard();
    if (!card) return;

    const rawText = String(card.textContent || '').replace(/\s+/g, ' ').trim();
    const titleCandidates = [...card.querySelectorAll('h1, h2, h3, strong')]
      .map((element) => String(element.textContent || '').trim())
      .filter(Boolean)
      .filter((value) => !value.toUpperCase().includes(STATUS_MARKER));

    const title = titleCandidates[0] || 'Værvakt-status';
    const description = rawText
      .replace(/VÆRVAKT-STATUS/gi, '')
      .replace(title, '')
      .replace(/\bNå\b/gi, '')
      .trim();

    const calm = /rolige forhold|ingen tydelige værfarer|ingen aktive varsler/i
      .test(`${title} ${description}`);

    // Eldre build kan fortsatt ligge i en nettlesercache. Fjern kortet fysisk,
    // ikke bare med CSS, slik at det ikke kan bli stående ved siden av banneret.
    card.remove();

    userAlerts = userAlerts.filter((alert) => alert.id !== 'legacy-vaervakt-status');

    if (!calm && (title || description)) {
      const warning = /fare|advarsel|kritisk|ekstrem|forsiktig/i
        .test(`${title} ${description}`);

      const normalized = normalizeAlert({
        id: 'legacy-vaervakt-status',
        title,
        description,
        icon: warning ? '⚠️' : '📢',
        severity: warning ? 'warning' : 'info',
        priority: 50,
      }, 'user');

      if (normalized) userAlerts.unshift(normalized);
    }

    render();
  }

  async function refreshMetAlerts() {
    removeLegacyStatusCard();

    const coordinates = await getCoordinates();
    if (!coordinates) {
      metAlerts = [];
      render();
      return;
    }

    const positionKey = `${coordinates.lat.toFixed(3)},${coordinates.lon.toFixed(3)}`;
    lastPositionKey = positionKey;

    try {
      const url = new URL(MET_API_URL, window.location.origin);
      url.searchParams.set('lat', coordinates.lat.toFixed(5));
      url.searchParams.set('lon', coordinates.lon.toFixed(5));

      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });

      if (!response.ok) return;

      const payload = await response.json();
      metAlerts = (Array.isArray(payload.alerts) ? payload.alerts : [])
        .map((alert) => normalizeAlert({ ...alert, priority: alert.priority ?? 100 }, 'met'))
        .filter(Boolean);

      render();
    } catch (error) {
      console.warn('Værvakt: kunne ikke hente farevarsler', error);
    }
  }

  window.vaervaktBanner = {
    show: showUserAlert,
    remove: removeUserAlert,
    refresh: refreshMetAlerts,
  };

  window.addEventListener('vaervakt:user-alert', (event) => showUserAlert(event.detail || {}));
  window.addEventListener('vaervakt:user-alert-remove', (event) => removeUserAlert(event.detail?.id));
  window.addEventListener('vaervakt:location-changed', refreshMetAlerts);

  window.addEventListener('storage', () => {
    const coordinates = coordinatesFromStorage();
    if (!coordinates) return;

    const nextKey = `${coordinates.lat.toFixed(3)},${coordinates.lon.toFixed(3)}`;
    if (nextKey !== lastPositionKey) refreshMetAlerts();
  });

  const observer = new MutationObserver(() => removeLegacyStatusCard());
  observer.observe(document.documentElement, { childList: true, subtree: true });

  window.addEventListener('load', refreshMetAlerts, { once: true });
  window.setInterval(refreshMetAlerts, REFRESH_MS);
})();

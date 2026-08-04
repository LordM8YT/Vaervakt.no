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

  function cleanDateValue(value) {
    return String(value || '').trim();
  }

  function parseAlertDate(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function formatAlertDate(value) {
    const date = parseAlertDate(value);
    if (!date) return '';

    return new Intl.DateTimeFormat('nb-NO', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    }).format(date).replace(',', ' kl.');
  }

  function formatPeriod(alert) {
    const starts = formatAlertDate(alert.startsAt);
    const ends = formatAlertDate(alert.endsAt);

    if (starts && ends) return `${starts} – ${ends}`;
    if (starts) return `${starts} – faren pågår`;
    if (ends) return `Pågår nå – til ${ends}`;
    return '';
  }

  function severityText(severity) {
    if (severity === 'danger') return 'Rødt farenivå';
    if (severity === 'warning') return 'Oransje farenivå';
    if (severity === 'notice') return 'Gult farenivå';
    return 'Informasjon';
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
      startsAt: cleanDateValue(alert.startsAt ?? alert.onset ?? alert.effective ?? alert.startTime ?? alert.validFrom),
      endsAt: cleanDateValue(alert.endsAt ?? alert.expires ?? alert.endTime ?? alert.validTo),
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
        padding: max(env(safe-area-inset-top, 0px), 8px) 10px 10px;
        box-sizing: border-box;
        background: #07172b;
        font-family: inherit;
      }
      .vv-alert {
        --vv-accent: #f59e0b;
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr) 38px;
        gap: 12px;
        align-items: start;
        width: min(1040px, 100%);
        margin: 0 auto 8px;
        padding: 13px 12px 13px 14px;
        box-sizing: border-box;
        color: #f8fafc;
        background: #0b1e35;
        border: 1px solid rgba(148, 163, 184, .2);
        border-left: 5px solid var(--vv-accent);
        border-radius: 14px;
        box-shadow: 0 8px 24px rgba(2, 6, 23, .28);
      }
      .vv-alert:last-child { margin-bottom: 0; }
      .vv-alert[data-severity="danger"] { --vv-accent: #ef4444; }
      .vv-alert[data-severity="warning"] { --vv-accent: #f59e0b; }
      .vv-alert[data-severity="notice"] { --vv-accent: #facc15; }
      .vv-alert[data-severity="info"] { --vv-accent: #38bdf8; }
      .vv-alert-icon-wrap {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        color: #07111f;
        background: var(--vv-accent);
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.22);
      }
      .vv-alert-icon {
        font-size: 1.45rem;
        line-height: 1;
      }
      .vv-alert-copy { min-width: 0; }
      .vv-alert-heading {
        display: flex;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 5px 8px;
        min-width: 0;
      }
      .vv-alert-title {
        display: block;
        color: #fff;
        font-size: .98rem;
        font-weight: 800;
        line-height: 1.3;
      }
      .vv-alert-level {
        color: var(--vv-accent);
        font-size: .76rem;
        font-weight: 800;
        line-height: 1.3;
      }
      .vv-alert-source {
        font-size: .67rem;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #93c5fd;
      }
      .vv-alert-detail {
        display: block;
        margin-top: 5px;
        color: #dbe7f4;
        font-size: .82rem;
        line-height: 1.45;
      }
      .vv-alert-area {
        display: block;
        margin-top: 5px;
        color: #b7c7da;
        font-size: .78rem;
        font-weight: 650;
        line-height: 1.4;
      }
      .vv-alert-period {
        display: flex;
        align-items: center;
        gap: 7px;
        width: fit-content;
        max-width: 100%;
        margin-top: 9px;
        padding: 7px 9px;
        border: 1px solid rgba(255,255,255,.14);
        border-radius: 9px;
        color: #fff;
        background: rgba(2, 11, 24, .72);
        font-size: .78rem;
        font-weight: 750;
        line-height: 1.35;
      }
      .vv-alert-period::before {
        content: '◷';
        flex: 0 0 auto;
        color: var(--vv-accent);
        font-size: 1rem;
      }
      .vv-alert-period-label {
        margin-right: 2px;
        color: #a9bbcf;
        font-size: .67rem;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
      }
      .vv-alert-close {
        display: grid;
        place-items: center;
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 9px;
        padding: 0;
        color: #fff;
        background: rgba(255,255,255,.08);
        font: inherit;
        font-size: 1.45rem;
        line-height: 1;
        cursor: pointer;
      }
      .vv-alert-close:hover { background: rgba(255,255,255,.14); }
      .vv-alert-close:focus-visible {
        outline: 2px solid var(--vv-accent);
        outline-offset: 2px;
      }
      @media (max-width: 640px) {
        #vv-alerts { padding-left: 7px; padding-right: 7px; }
        .vv-alert {
          grid-template-columns: 36px minmax(0, 1fr) 32px;
          gap: 9px;
          padding: 11px 8px 11px 10px;
          border-radius: 12px;
        }
        .vv-alert-icon-wrap { width: 34px; height: 34px; border-radius: 8px; }
        .vv-alert-icon { font-size: 1.15rem; }
        .vv-alert-title { font-size: .9rem; }
        .vv-alert-level { font-size: .7rem; }
        .vv-alert-detail { font-size: .78rem; }
        .vv-alert-area { font-size: .74rem; }
        .vv-alert-period {
          width: 100%;
          box-sizing: border-box;
          align-items: flex-start;
          flex-wrap: wrap;
          padding: 8px 9px;
          font-size: .76rem;
        }
        .vv-alert-period-label { flex-basis: calc(100% - 24px); }
        .vv-alert-close { width: 30px; height: 30px; font-size: 1.3rem; }
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

      const iconWrap = document.createElement('span');
      iconWrap.className = 'vv-alert-icon-wrap';
      iconWrap.setAttribute('aria-hidden', 'true');

      const icon = document.createElement('span');
      icon.className = 'vv-alert-icon';
      icon.textContent = alert.icon || '⚠️';
      iconWrap.appendChild(icon);

      const copy = document.createElement('div');
      copy.className = 'vv-alert-copy';

      const heading = document.createElement('div');
      heading.className = 'vv-alert-heading';

      const title = document.createElement('strong');
      title.className = 'vv-alert-title';
      title.textContent = alert.title || 'Varsel';

      const level = document.createElement('span');
      level.className = 'vv-alert-level';
      level.textContent = severityText(alert.severity);

      const source = document.createElement('small');
      source.className = 'vv-alert-source';
      source.textContent = alert.source === 'met' ? 'MET' : 'Værvakt';

      heading.append(title, level, source);
      copy.appendChild(heading);

      const detailText = [alert.description, alert.instruction].filter(Boolean).join(' – ');
      if (detailText) {
        const detail = document.createElement('span');
        detail.className = 'vv-alert-detail';
        detail.textContent = detailText;
        copy.appendChild(detail);
      }

      if (alert.area) {
        const area = document.createElement('span');
        area.className = 'vv-alert-area';
        area.textContent = `Område: ${alert.area}`;
        copy.appendChild(area);
      }

      const periodText = formatPeriod(alert);
      if (periodText) {
        const period = document.createElement('div');
        period.className = 'vv-alert-period';

        const periodLabel = document.createElement('span');
        periodLabel.className = 'vv-alert-period-label';
        periodLabel.textContent = 'Tidsperiode';

        const periodValue = document.createElement('span');
        periodValue.textContent = periodText;

        period.append(periodLabel, periodValue);
        copy.appendChild(period);
      }

      const close = document.createElement('button');
      close.className = 'vv-alert-close';
      close.type = 'button';
      close.setAttribute('aria-label', 'Lukk varsel');
      close.textContent = '×';
      close.addEventListener('click', () => dismiss(alert, banner));

      banner.append(iconWrap, copy, close);
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

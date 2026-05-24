const weatherState = {
  rain: [
    { hour: '16:00', amount: 0, probability: 0 },
    { hour: '17:00', amount: 0, probability: 0 },
    { hour: '18:00', amount: 0, probability: 0 },
    { hour: '19:00', amount: 0, probability: 0 },
    { hour: '20:00', amount: 0, probability: 0 },
    { hour: '21:00', amount: 0, probability: 0 },
  ],
  temperature: [
    { hour: '16:00', value: 21 },
    { hour: '17:00', value: 21 },
    { hour: '18:00', value: 21 },
    { hour: '19:00', value: 20 },
    { hour: '20:00', value: 20 },
    { hour: '21:00', value: 19 },
    { hour: '22:00', value: 19 },
    { hour: '23:00', value: 18 },
  ],
  forecast: [
    { day: 'I DAG', icon: '🌤️', temp: 21, width: 77.5 },
    { day: 'MAN', icon: '🌤️', temp: 21, width: 77.5 },
    { day: 'TIR', icon: '☀️', temp: 19, width: 72.5 },
    { day: 'ONS', icon: '☀️', temp: 20, width: 75 },
    { day: 'TOR', icon: '⛅', temp: 16, width: 65 },
  ],
  observations: [
    { icon: '⏳', time: 'Laster', reporter: 'Henter observasjoner', condition: 'Kobler til databasen', temp: 0 },
  ],
  map: null,
  mapMarkers: [],
};

const navItems = [
  { id: 'home', label: 'Hjem', active: true, icon: '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 10v10h14V10"></path><path d="M9 20v-6h6v6"></path>' },
  { id: 'map', label: 'Kart', active: false, icon: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3Z"></path><path d="M9 3v15"></path><path d="M15 6v15"></path>' },
  { id: 'report', label: 'Rapporter', active: false, icon: '<circle cx="12" cy="12" r="9"></circle><path d="M8 12h8"></path><path d="M12 8v8"></path>' },
  { id: 'favorites', label: 'Favoritter', active: false, icon: '<path d="m12 3 2.7 5.47 6.03.88-4.36 4.25 1.03 6-5.4-2.84-5.4 2.84 1.03-6-4.36-4.25 6.03-.88Z"></path>' },
  { id: 'alerts', label: 'Varsler', active: false, icon: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path>' },
];

function updateClock() {
  const clock = document.querySelector('#clock');
  if (!clock) return;

  clock.textContent = new Intl.DateTimeFormat('nb-NO', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date());
}

function createSvgElement(name, attrs = {}) {
  const element = document.createElementNS('http://www.w3.org/2000/svg', name);
  Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, value));
  return element;
}

function drawRainChart() {
  const svg = document.querySelector('#rain-chart');
  if (!svg) return;

  svg.replaceChildren();
  const width = 500;
  const top = 4;
  const bottom = 114;
  const left = 24;
  const usableWidth = 426;
  const points = weatherState.rain.map((item, index) => ({
    x: left + (usableWidth / (weatherState.rain.length - 1)) * index,
    y: bottom - item.probability * 1.1,
    ...item,
  }));

  [bottom, 59, top].forEach((y) => {
    svg.appendChild(createSvgElement('line', {
      class: 'chart-grid-line',
      x1: '-20',
      x2: '492',
      y1: y,
      y2: y,
    }));
  });

  points.forEach((point) => {
    svg.appendChild(createSvgElement('rect', {
      class: 'rain-bar',
      x: point.x - 7,
      y: bottom - Math.max(point.amount * 20, 0),
      width: 14,
      height: Math.max(point.amount * 20, 0),
      rx: 3,
    }));

    const label = createSvgElement('text', { class: 'chart-axis-label', x: point.x, y: 126 });
    label.textContent = point.hour;
    svg.appendChild(label);
  });

  const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' ');
  svg.appendChild(createSvgElement('path', { class: 'probability-line', d: path }));
}

function drawTemperatureChart() {
  const svg = document.querySelector('#temperature-chart');
  if (!svg) return;

  svg.replaceChildren();
  const temps = weatherState.temperature;
  const width = 492;
  const top = 18;
  const bottom = 90;
  const min = Math.min(...temps.map((item) => item.value)) - 1;
  const max = Math.max(...temps.map((item) => item.value)) + 1;
  const points = temps.map((item, index) => {
    const x = (width / (temps.length - 1)) * index;
    const y = top + ((max - item.value) / (max - min)) * (bottom - top);
    return { ...item, x, y };
  });

  const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' ');
  svg.appendChild(createSvgElement('path', { class: 'temperature-line', d: path }));

  points.forEach((point, index) => {
    svg.appendChild(createSvgElement('circle', {
      class: 'temperature-dot',
      cx: point.x,
      cy: point.y,
      r: 4,
    }));

    if (index > 0) {
      const label = createSvgElement('text', { class: 'chart-axis-label', x: point.x, y: 112 });
      label.textContent = point.hour;
      svg.appendChild(label);
    }
  });
}

function renderForecast() {
  const list = document.querySelector('#forecast-list');
  if (!list) return;

  list.replaceChildren(...weatherState.forecast.map((item) => {
    const row = document.createElement('div');
    row.className = 'forecast-row';
    row.innerHTML = `
      <span class="forecast-day">${item.day}</span>
      <span class="forecast-icon" aria-hidden="true">${item.icon}</span>
      <span class="flex-1"></span>
      <span class="forecast-temp">${item.temp}°</span>
      <span class="forecast-track" aria-hidden="true">
        <span class="forecast-fill forecast-fill--${String(item.width).replace('.', '-')}"></span>
      </span>
    `;
    return row;
  }));
}

function renderObservations() {
  const list = document.querySelector('#observations-list');
  if (!list) return;

  list.replaceChildren(...weatherState.observations.map(createObservationElement));
}

function createObservationElement(item) {
    const row = document.createElement('article');
    row.className = 'obs-item';
    row.innerHTML = `
      <span class="obs-icon" aria-hidden="true">${escapeHtml(item.icon)}</span>
      <div class="obs-body">
        <p class="obs-time">${escapeHtml(item.time)}</p>
        <p class="obs-name">${escapeHtml(item.reporter)}</p>
        <p class="obs-condition">${escapeHtml(item.condition)}</p>
      </div>
      <span class="obs-temp">${Number(item.temp) || 0}°</span>
    `;
    return row;
}

async function loadReports() {
  try {
    const response = await fetch('api/reports.php?limit=20', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Kunne ikke hente rapporter');
    }

    weatherState.observations = payload.reports.length
      ? payload.reports
      : [{ icon: '🌤️', time: 'Ingen data', reporter: 'Ingen observasjoner ennå', condition: 'Send den første rapporten', temp: 0 }];
  } catch (error) {
    console.warn('Kunne ikke hente DB-observasjoner:', error);
    weatherState.observations = [{ icon: '⚠️', time: 'API utilgjengelig', reporter: 'Kunne ikke hente observasjoner', condition: 'Sjekk DB/API-oppsett', temp: 0 }];
  }

  renderObservations();
  renderMapView();
}

function renderNavigation() {
  const container = document.querySelector('#bottom-nav-items');
  if (!container) return;

  container.replaceChildren(...navItems.map((item) => {
    const button = document.createElement('button');
    button.className = 'nav-button';
    button.type = 'button';
    button.dataset.navItem = item.id;
    button.dataset.active = String(item.active);
    button.innerHTML = `
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${item.active ? '2.2' : '1.7'}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${item.icon}</svg>
      <span class="nav-label">${item.label}</span>
    `;
    button.addEventListener('click', () => setActiveNavItem(item.id));
    return button;
  }));
}

function setActiveNavItem(activeId) {
  document.querySelectorAll('[data-view]').forEach((view) => {
    view.classList.toggle('hidden-view', view.dataset.view !== activeId);
  });

  document.querySelectorAll('[data-nav-item]').forEach((button) => {
    const isActive = button.dataset.navItem === activeId;
    button.dataset.active = String(isActive);
    const icon = button.querySelector('svg');
    if (icon) icon.setAttribute('stroke-width', isActive ? '2.2' : '1.7');
  });

  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (activeId === 'map') renderMapView();
  if (activeId === 'favorites') renderFavorites();
}

function renderMapList() {
  const list = document.querySelector('#map-list');
  if (!list) return;

  const located = weatherState.observations.filter((item) => item.lat && item.lon);
  const items = located.length ? located : weatherState.observations;
  list.replaceChildren(...items.map(createObservationElement));
}

function renderMapView() {
  renderMapList();
  initMap();
  renderMapMarkers();
}

function initMap() {
  const mapEl = document.querySelector('#leaflet-map');
  if (!mapEl || weatherState.map || typeof L === 'undefined') return;

  weatherState.map = L.map(mapEl, {
    zoomControl: true,
    attributionControl: false,
  }).setView([58.1504, 7.9470], 9);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(weatherState.map);
}

function renderMapMarkers() {
  const status = document.querySelector('#map-status');
  if (!weatherState.map) {
    if (status) status.textContent = typeof L === 'undefined' ? 'Kartbiblioteket kunne ikke lastes.' : 'Kartet klargjøres...';
    return;
  }

  weatherState.mapMarkers.forEach((marker) => marker.remove());
  weatherState.mapMarkers = [];

  const located = weatherState.observations.filter((item) => Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon)));
  if (!located.length) {
    weatherState.map.setView([58.1504, 7.9470], 9);
    if (status) status.textContent = 'Ingen rapporter med koordinater ennå. Listen under viser DB-observasjoner uten kartpunkt.';
    window.setTimeout(() => weatherState.map.invalidateSize(), 80);
    return;
  }

  const bounds = [];
  located.forEach((item) => {
    const latLng = [Number(item.lat), Number(item.lon)];
    bounds.push(latLng);
    const marker = L.marker(latLng)
      .addTo(weatherState.map)
      .bindPopup(`<strong>${escapeHtml(item.reporter)}</strong><br>${escapeHtml(item.condition)} · ${Number(item.temp) || 0}°`);
    weatherState.mapMarkers.push(marker);
  });

  weatherState.map.fitBounds(bounds, { padding: [28, 28], maxZoom: 13 });
  if (status) status.textContent = `${located.length} observasjon${located.length === 1 ? '' : 'er'} med kartpunkt.`;
  window.setTimeout(() => weatherState.map.invalidateSize(), 80);
}

function renderFavorites() {
  const list = document.querySelector('#favorites-list');
  if (!list) return;

  const favorites = JSON.parse(localStorage.getItem('vaervakt_favorites') || '[]');
  if (!favorites.length) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = 'Ingen favoritter lagret ennå.';
    list.replaceChildren(empty);
    return;
  }

  list.replaceChildren(...favorites.map((favorite) => {
    const item = document.createElement('div');
    item.className = 'empty-state';
    item.textContent = favorite.name || favorite.location || 'Lagret sted';
    return item;
  }));
}

function bindReportForm() {
  const form = document.querySelector('#report-form');
  if (!form) return;

  const positionButton = document.querySelector('#use-position-button');
  if (positionButton) {
    positionButton.addEventListener('click', () => {
      if (!navigator.geolocation) {
        showToast('Posisjon støttes ikke i denne nettleseren.');
        return;
      }

      navigator.geolocation.getCurrentPosition((position) => {
        document.querySelector('#report-lat').value = String(position.coords.latitude);
        document.querySelector('#report-lon').value = String(position.coords.longitude);
        showToast('Posisjon lagt til rapporten.');
      }, () => {
        showToast('Kunne ikke hente posisjon.');
      }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());

    try {
      const response = await fetch('api/report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Kunne ikke lagre rapport');
      }

      showToast('Rapport sendt.');
      form.reset();
      await loadReports();
      setActiveNavItem('home');
    } catch (error) {
      showToast(error.message || 'Kunne ikke sende rapport.');
    }
  });
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));
}

function showToast(message, timeout = 3200) {
  const region = document.querySelector('#toast-region');
  if (!region) return;

  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = message;
  region.appendChild(toast);

  window.setTimeout(() => toast.remove(), timeout);
}

function initApp() {
  updateClock();
  drawRainChart();
  drawTemperatureChart();
  renderForecast();
  renderObservations();
  renderNavigation();
  bindReportForm();
  renderFavorites();
  loadReports();
  window.setInterval(updateClock, 30_000);
}

window.VaervaktApp = { showToast };
document.addEventListener('DOMContentLoaded', initApp);

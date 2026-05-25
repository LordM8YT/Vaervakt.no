const weatherState = {
  location: {
    id: 'kristiansand-no',
    name: 'Kristiansand, NO',
    lat: 58.1504,
    lon: 7.9470,
    source: 'default',
  },
  current: {
    temperature: 21,
    feelsLike: 21,
    condition: 'Henter MET-data',
  },
  uvIndex: null,
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

const favoritesStorageKey = 'vaervakt_favorites';

function isValidCoordinate(lat, lon) {
  return Number.isFinite(lat) && Number.isFinite(lon)
    && lat >= -90 && lat <= 90
    && lon >= -180 && lon <= 180;
}

function getPosition(options = {}) {
  if (!navigator.geolocation) {
    return Promise.reject(new Error('Geolokasjon støttes ikke i denne nettleseren.'));
  }

  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0,
      ...options,
    });
  });
}

function setWeatherLocationFromPosition(position) {
  const lat = Number(position?.coords?.latitude);
  const lon = Number(position?.coords?.longitude);
  if (!isValidCoordinate(lat, lon)) {
    throw new Error('Nettleseren ga ugyldige koordinater.');
  }

  weatherState.location = {
    id: `user-${lat.toFixed(4)}-${lon.toFixed(4)}`,
    name: 'Din posisjon',
    lat: Number(lat.toFixed(6)),
    lon: Number(lon.toFixed(6)),
    source: 'user',
  };
}

async function useUserLocationForWeather() {
  try {
    const position = await getPosition();
    setWeatherLocationFromPosition(position);
    return true;
  } catch (error) {
    console.warn('Kunne ikke hente brukerposisjon for værvarsel:', error);
    return false;
  }
}

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
  if (weatherState.rain.length < 2) return;
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
  if (temps.length < 2) return;
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

  const temps = weatherState.forecast.map((item) => Number(item.temp) || 0);
  const min = Math.min(...temps);
  const max = Math.max(...temps);
  list.replaceChildren(...weatherState.forecast.map((item) => {
    const span = max === min ? 50 : ((Number(item.temp) - min) / (max - min)) * 35 + 55;
    const row = document.createElement('div');
    row.className = 'forecast-row';
    row.innerHTML = `
      <span class="forecast-day">${item.day}</span>
      <span class="forecast-icon" aria-hidden="true">${item.icon}</span>
      <span class="flex-1"></span>
      <span class="forecast-temp">${item.temp}°</span>
      <span class="forecast-track" aria-hidden="true">
        <span class="forecast-fill forecast-fill--${getForecastWidthClass(span)}"></span>
      </span>
    `;
    return row;
  }));
}

function getForecastWidthClass(width) {
  const rounded = Math.max(50, Math.min(90, Math.round(width / 5) * 5));
  return String(rounded);
}

function renderCurrentWeather() {
  const title = document.querySelector('#current-weather-title');
  const temperature = document.querySelector('#temperature');
  const feelsLike = document.querySelector('#feels-like');
  const condition = document.querySelector('#weather-condition');

  if (title) title.textContent = weatherState.location.name;
  if (temperature) temperature.textContent = String(Math.round(Number(weatherState.current?.temperature ?? 0)));
  if (feelsLike) feelsLike.textContent = `${Math.round(Number(weatherState.current?.feelsLike ?? weatherState.current?.temperature ?? 0))}°C`;
  if (condition) condition.textContent = weatherState.current?.condition || '';
}

function renderWeatherMeta() {
  const uvIndex = document.querySelector('#uv-index');
  const uvStatus = document.querySelector('#uv-status');
  const favoriteButton = document.querySelector('#favorite-location-button');

  if (uvIndex) uvIndex.textContent = weatherState.uvIndex === null ? '--' : String(weatherState.uvIndex);
  if (uvStatus) uvStatus.textContent = weatherState.uvIndex === null ? 'Henter MET' : getUvStatus(weatherState.uvIndex);

  if (favoriteButton) {
    const note = favoriteButton.querySelector('.weather-meta-note');
    const value = favoriteButton.querySelector('.weather-meta-value');
    const isSaved = getFavorites().some((favorite) => favorite.id === weatherState.location.id);
    favoriteButton.dataset.saved = String(isSaved);
    favoriteButton.setAttribute('aria-pressed', String(isSaved));
    if (value) value.textContent = isSaved ? 'Lagret' : 'Lagre';
    if (note) note.textContent = weatherState.location.name;
  }
}

async function loadWeather({ preferUserLocation = false } = {}) {
  if (preferUserLocation) {
    const usingUserLocation = await useUserLocationForWeather();
    if (!usingUserLocation) {
      showToast('Bruker Kristiansand som reserve fordi posisjon ikke kunne hentes.');
    }
  }

  try {
    const params = new URLSearchParams({
      lat: String(weatherState.location.lat),
      lon: String(weatherState.location.lon),
      source: weatherState.location.source || 'default',
    });
    const response = await fetch(`api/weather.php?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Kunne ikke hente værdata fra MET');
    }

    weatherState.location = payload.location || weatherState.location;
    weatherState.current = payload.current || weatherState.current;
    weatherState.uvIndex = Number(payload.current?.uvIndex ?? 0);
    weatherState.rain = Array.isArray(payload.rain) ? payload.rain : weatherState.rain;
    weatherState.temperature = Array.isArray(payload.temperature) ? payload.temperature : weatherState.temperature;
    weatherState.forecast = Array.isArray(payload.forecast) && payload.forecast.length ? payload.forecast : weatherState.forecast;
  } catch (error) {
    console.warn('Kunne ikke hente MET-værdata:', error);
    showToast('Kunne ikke hente værdata fra MET akkurat nå.');
  }

  renderCurrentWeather();
  renderWeatherMeta();
  drawRainChart();
  drawTemperatureChart();
  renderForecast();
}

function getUvStatus(index) {
  if (index < 3) return 'Lav';
  if (index < 6) return 'Moderat';
  if (index < 8) return 'Høy';
  return 'Svært høy';
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
  if (isViewVisible('map')) {
    renderMapView();
  } else {
    renderMapList();
  }
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

  const located = weatherState.observations.filter((item) => Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon)));
  const items = located.length ? located : weatherState.observations;
  list.replaceChildren(...items.map(createObservationElement));
}

function renderMapView() {
  if (!isViewVisible('map')) {
    renderMapList();
    return;
  }

  renderMapList();
  initMap();
  renderMapMarkers();
}

function isViewVisible(viewName) {
  const view = document.querySelector(`[data-view="${viewName}"]`);
  return Boolean(view && !view.classList.contains('hidden-view'));
}

function initMap() {
  const mapEl = document.querySelector('#leaflet-map');
  if (!mapEl || weatherState.map || typeof L === 'undefined') return;

  weatherState.map = L.map(mapEl, {
    zoomControl: true,
    attributionControl: false,
  }).setView([weatherState.location.lat, weatherState.location.lon], 9);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(weatherState.map);

  window.setTimeout(() => weatherState.map.invalidateSize(), 80);
}

function createMapMarkerIcon() {
  return L.divIcon({
    className: 'vv-map-marker',
    html: '<span aria-hidden="true"></span>',
    iconSize: [28, 28],
    iconAnchor: [14, 28],
    popupAnchor: [0, -24],
  });
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
    const marker = L.marker(latLng, { icon: createMapMarkerIcon() })
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

  const favorites = getFavorites();
  if (!favorites.length) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = 'Ingen favoritter lagret ennå. Trykk Lagre på hjemskjermen for å legge til stedet.';
    list.replaceChildren(empty);
    return;
  }

  list.replaceChildren(...favorites.map((favorite) => {
    const item = document.createElement('article');
    item.className = 'favorite-item';
    item.innerHTML = `
      <div>
        <p class="favorite-title">${escapeHtml(favorite.name || favorite.location || 'Lagret sted')}</p>
        <p class="favorite-meta">${escapeHtml(favorite.label || 'Lokalt værsted')}</p>
      </div>
      <button class="favorite-remove" type="button" data-remove-favorite="${escapeHtml(favorite.id)}">Fjern</button>
    `;
    return item;
  }));
}

function getFavorites() {
  try {
    const favorites = JSON.parse(localStorage.getItem(favoritesStorageKey) || '[]');
    return Array.isArray(favorites) ? favorites : [];
  } catch {
    return [];
  }
}

function saveFavorites(favorites) {
  localStorage.setItem(favoritesStorageKey, JSON.stringify(favorites));
  renderWeatherMeta();
  renderFavorites();
}

function toggleCurrentLocationFavorite() {
  const favorites = getFavorites();
  const index = favorites.findIndex((favorite) => favorite.id === weatherState.location.id);

  if (index >= 0) {
    favorites.splice(index, 1);
    saveFavorites(favorites);
    showToast('Favoritt fjernet.');
    return;
  }

  favorites.unshift({
    ...weatherState.location,
    label: 'Aktivt sted',
    savedAt: new Date().toISOString(),
  });
  saveFavorites(favorites);
  showToast('Favoritt lagret.');
}

function bindFavorites() {
  const favoriteButton = document.querySelector('#favorite-location-button');
  if (favoriteButton) {
    favoriteButton.addEventListener('click', toggleCurrentLocationFavorite);
  }

  const favoritesList = document.querySelector('#favorites-list');
  if (favoritesList) {
    favoritesList.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;

      const button = target.closest('[data-remove-favorite]');
      if (!button) return;

      const id = button.dataset.removeFavorite;
      saveFavorites(getFavorites().filter((favorite) => favorite.id !== id));
      showToast('Favoritt fjernet.');
    });
  }
}

function bindReportForm() {
  const form = document.querySelector('#report-form');
  if (!form) return;

  const positionButton = document.querySelector('#use-position-button');
  if (positionButton) {
    positionButton.addEventListener('click', async () => {
      try {
        const position = await getPosition();
        document.querySelector('#report-lat').value = Number(position.coords.latitude).toFixed(6);
        document.querySelector('#report-lon').value = Number(position.coords.longitude).toFixed(6);
        showToast('Posisjon lagt til rapporten.');
      } catch (error) {
        showToast(error.message || 'Kunne ikke hente posisjon.');
      }
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
  renderCurrentWeather();
  drawRainChart();
  drawTemperatureChart();
  renderForecast();
  renderWeatherMeta();
  renderObservations();
  renderNavigation();
  bindReportForm();
  bindFavorites();
  renderFavorites();
  loadWeather({ preferUserLocation: true });
  loadReports();
  window.setInterval(updateClock, 30_000);
}

window.VaervaktApp = { showToast };
document.addEventListener('DOMContentLoaded', initApp);

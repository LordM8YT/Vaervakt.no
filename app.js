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
    icon: '🌤️',
    windSpeed: 0,
    humidity: 0,
  },
  summary: {
    headline: 'Rolig værbilde',
    detail: 'Værvakt kombinerer MET-varsel med lokale rapporter når de finnes.',
  },
  insights: [
    { label: 'Vind', value: '--', note: 'Henter' },
    { label: 'Luft', value: '--', note: 'Fuktighet' },
    { label: 'Regn', value: '--', note: 'Neste 6 timer' },
  ],
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
  hourly: [
    { hour: 'Nå', icon: '🌤️', condition: 'Henter', temp: 21, precipitation: 0, probability: 0, windSpeed: 0 },
  ],
  bathing: {
    score: 0,
    label: 'Henter badevær',
    emoji: '🌊',
    description: 'Vi sjekker luft, vind og nedbør før vi gir badevær-vurdering.',
    airTemperature: 0,
    windSpeed: 0,
    rainAmount: 0,
    rainProbability: 0,
    uvIndex: 0,
    waterTemperature: null,
    waterTemperatureLocation: null,
    waterTemperatureTime: null,
    waterTemperatureDistanceKm: null,
    waterTemperatureHeated: false,
    credit: null,
    source: 'Beregnes fra MET-varsel.',
  },
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
let weatherRequestSequence = 0;
let reportsRequestSequence = 0;

function getActiveLocationKey() {
  return [
    weatherState.location.id,
    weatherState.location.source,
    weatherState.location.searchQuery || '',
    Number(weatherState.location.lat).toFixed(5),
    Number(weatherState.location.lon).toFixed(5),
  ].join('|');
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
  const source = document.querySelector('#location-source');
  const temperature = document.querySelector('#temperature');
  const feelsLike = document.querySelector('#feels-like');
  const condition = document.querySelector('#weather-condition');

  if (title) title.textContent = weatherState.location.name;
  if (source) source.textContent = getLocationSourceText();
  if (temperature) temperature.textContent = String(Math.round(Number(weatherState.current?.temperature ?? 0)));
  if (feelsLike) feelsLike.textContent = `${Math.round(Number(weatherState.current?.feelsLike ?? weatherState.current?.temperature ?? 0))}°C`;
  if (condition) condition.textContent = weatherState.current?.condition || '';
}

function getLocationSourceText() {
  if (weatherState.location.source === 'user') return 'Data fra posisjonen du har delt.';
  if (weatherState.location.source === 'search') return `Data fra søket sted: ${weatherState.location.name}.`;
  return 'Standarddata fra Kristiansand, NO. Del posisjon eller søk etter sted for lokalt varsel.';
}

function renderWeatherMeta() {
  const uvIndex = document.querySelector('#uv-index');
  const uvStatus = document.querySelector('#uv-status');
  const favoriteButton = document.querySelector('#favorite-location-button');
  const weatherPositionButton = document.querySelector('#weather-position-button');

  if (uvIndex) uvIndex.textContent = weatherState.uvIndex === null ? '--' : String(weatherState.uvIndex);
  if (uvStatus) uvStatus.textContent = weatherState.uvIndex === null ? 'Henter MET' : getUvStatus(weatherState.uvIndex);

  if (weatherPositionButton) {
    const note = weatherPositionButton.querySelector('.weather-meta-note');
    if (note) note.textContent = weatherState.location.name;
  }

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

function renderWeatherSummary() {
  const headline = document.querySelector('#weather-summary-headline');
  const detail = document.querySelector('#weather-summary-detail');
  const icon = document.querySelector('#weather-hero-icon');

  if (headline) headline.textContent = weatherState.summary?.headline || weatherState.current?.condition || 'Værstatus';
  if (detail) detail.textContent = weatherState.summary?.detail || 'Lokalt MET-varsel kombinert med rapporter fra folk på bakken.';
  if (icon) icon.textContent = weatherState.current?.icon || '🌤️';
}

function renderWeatherInsights() {
  const list = document.querySelector('#weather-insights');
  if (!list) return;

  const insights = Array.isArray(weatherState.insights) && weatherState.insights.length
    ? weatherState.insights
    : [
      { label: 'Vind', value: '--', note: 'Henter' },
      { label: 'Luft', value: '--', note: 'Fuktighet' },
      { label: 'Regn', value: '--', note: 'Neste 6 timer' },
    ];

  list.replaceChildren(...insights.slice(0, 3).map((item) => {
    const card = document.createElement('article');
    card.className = 'weather-insight-card';
    card.innerHTML = `
      <span class="weather-insight-label">${escapeHtml(item.label)}</span>
      <strong class="weather-insight-value">${escapeHtml(item.value)}</strong>
      <span class="weather-insight-note">${escapeHtml(item.note)}</span>
    `;
    return card;
  }));
}

function renderRainSummary() {
  const summary = document.querySelector('#rain-summary');
  if (!summary) return;

  const rain = Array.isArray(weatherState.rain) ? weatherState.rain : [];
  const total = rain.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const maxProbability = rain.reduce((max, item) => Math.max(max, Number(item.probability || 0)), 0);

  if (total >= 4 || maxProbability >= 75) {
    summary.textContent = `${total.toFixed(1).replace('.', ',')} mm mulig`;
  } else if (total > 0 || maxProbability >= 30) {
    summary.textContent = `Liten sjanse · ${Math.round(maxProbability)}%`;
  } else {
    summary.textContent = 'Ingen nedbør forventet';
  }
}

function renderHourlyForecast() {
  const list = document.querySelector('#hourly-list');
  if (!list) return;

  const hourly = Array.isArray(weatherState.hourly) && weatherState.hourly.length ? weatherState.hourly : [];
  list.replaceChildren(...hourly.slice(0, 12).map((item, index) => {
    const card = document.createElement('article');
    card.className = 'hourly-card';
    const hour = index === 0 ? 'Nå' : item.hour;
    const precipitation = Number(item.precipitation || 0);
    const probability = Number(item.probability || 0);
    card.innerHTML = `
      <span class="hourly-time">${escapeHtml(hour)}</span>
      <span class="hourly-icon" aria-hidden="true">${escapeHtml(item.icon || '🌤️')}</span>
      <strong class="hourly-temp">${Math.round(Number(item.temp) || 0)}°</strong>
      <span class="hourly-meta">${precipitation > 0 ? `${precipitation.toFixed(1).replace('.', ',')} mm` : `${Math.round(probability)}%`}</span>
      <span class="hourly-wind">${Number(item.windSpeed || 0).toFixed(1).replace('.', ',')} m/s</span>
    `;
    return card;
  }));
}

function formatBathingSource(bathing) {
  if (!bathing) return 'Beregnes fra MET-varsel.';

  const parts = [];
  if (bathing.credit) parts.push(bathing.credit);
  if (bathing.waterTemperatureLocation) parts.push(bathing.waterTemperatureLocation);
  if (Number.isFinite(Number(bathing.waterTemperatureDistanceKm))) {
    parts.push(`${Number(bathing.waterTemperatureDistanceKm).toFixed(1).replace('.', ',')} km unna`);
  }
  if (bathing.waterTemperatureTime) {
    const date = new Date(bathing.waterTemperatureTime);
    if (!Number.isNaN(date.getTime())) {
      parts.push(new Intl.DateTimeFormat('nb-NO', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      }).format(date));
    }
  }
  if (bathing.waterTemperatureHeated) parts.push('oppvarmet vann');

  return parts.length ? parts.join(' · ') : (bathing.source || 'Beregnes fra MET-varsel.');
}

function renderBathingWeather() {
  const bathing = weatherState.bathing || {};
  const emoji = document.querySelector('#bathing-emoji');
  const score = document.querySelector('#bathing-score');
  const fill = document.querySelector('#bathing-score-fill');
  const label = document.querySelector('#bathing-label');
  const description = document.querySelector('#bathing-description');
  const metrics = document.querySelector('#bathing-metrics');
  const source = document.querySelector('#bathing-source');

  const numericScore = Math.max(0, Math.min(100, Number(bathing.score) || 0));
  if (emoji) emoji.textContent = bathing.emoji || '🌊';
  if (score) score.textContent = `${Math.round(numericScore)}%`;
  if (fill) fill.style.width = `${numericScore}%`;
  if (label) label.textContent = bathing.label || 'Badevær';
  if (description) description.textContent = bathing.description || 'Beregnet fra temperatur, vind og nedbør.';
  if (source) source.textContent = formatBathingSource(bathing);

  if (metrics) {
    const hasWaterTemperature = bathing.waterTemperature !== null
      && bathing.waterTemperature !== undefined
      && bathing.waterTemperature !== ''
      && Number.isFinite(Number(bathing.waterTemperature));
    const waterTemp = hasWaterTemperature
      ? `${Number(bathing.waterTemperature).toFixed(1).replace('.', ',')}°`
      : 'Ingen Yr-måling';
    const items = [
      { label: 'Luft', value: `${Math.round(Number(bathing.airTemperature) || 0)}°` },
      { label: 'Vann', value: waterTemp },
      { label: 'Vind', value: `${Number(bathing.windSpeed || 0).toFixed(1).replace('.', ',')} m/s` },
      { label: 'Regn', value: Number(bathing.rainAmount || 0) > 0 ? `${Number(bathing.rainAmount).toFixed(1).replace('.', ',')} mm` : 'Tørt' },
    ];

    metrics.replaceChildren(...items.map((item) => {
      const metric = document.createElement('article');
      metric.className = 'bathing-metric';
      metric.innerHTML = `
        <span>${escapeHtml(item.label)}</span>
        <strong>${escapeHtml(item.value)}</strong>
      `;
      return metric;
    }));
  }
}

function renderWeatherExperience() {
  renderWeatherSummary();
  renderWeatherInsights();
  renderRainSummary();
  renderHourlyForecast();
  renderBathingWeather();
}

async function loadWeather() {
  const requestId = ++weatherRequestSequence;
  const locationKey = getActiveLocationKey();
  const lat = weatherState.location.lat;
  const lon = weatherState.location.lon;

  try {
    const response = await fetch(`api/weather.php?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Kunne ikke hente værdata fra MET');
    }

    if (requestId !== weatherRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }

    const previousLocation = weatherState.location;
    weatherState.location = {
      ...previousLocation,
      ...(payload.location || {}),
      id: previousLocation.id,
      name: previousLocation.name,
      source: previousLocation.source,
    };
    weatherState.current = payload.current || weatherState.current;
    weatherState.summary = payload.summary || weatherState.summary;
    weatherState.insights = Array.isArray(payload.insights) && payload.insights.length ? payload.insights : weatherState.insights;
    weatherState.uvIndex = Number(payload.current?.uvIndex ?? 0);
    weatherState.rain = Array.isArray(payload.rain) ? payload.rain : weatherState.rain;
    weatherState.temperature = Array.isArray(payload.temperature) ? payload.temperature : weatherState.temperature;
    weatherState.hourly = Array.isArray(payload.hourly) && payload.hourly.length ? payload.hourly : weatherState.hourly;
    weatherState.bathing = payload.bathing || weatherState.bathing;
    weatherState.forecast = Array.isArray(payload.forecast) && payload.forecast.length ? payload.forecast : weatherState.forecast;
  } catch (error) {
    if (requestId !== weatherRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }
    console.warn('Kunne ikke hente MET-værdata:', error);
    showToast('Kunne ikke hente værdata fra MET akkurat nå.');
  }

  renderCurrentWeather();
  renderWeatherMeta();
  renderWeatherExperience();
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
  const requestId = ++reportsRequestSequence;
  const locationKey = getActiveLocationKey();

  try {
    const params = new URLSearchParams({ limit: '20' });
    const hasCoords = Number.isFinite(Number(weatherState.location.lat)) && Number.isFinite(Number(weatherState.location.lon));
    if (hasCoords) {
      params.set('lat', String(weatherState.location.lat));
      params.set('lon', String(weatherState.location.lon));
      params.set('radiusKm', weatherState.location.source === 'user' ? '15' : '25');
    }
    if (weatherState.location.source === 'search') {
      params.set('location', weatherState.location.searchQuery || weatherState.location.name);
    } else if (weatherState.location.source === 'default') {
      params.set('location', weatherState.location.name);
    }

    const response = await fetch(`api/reports.php?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Kunne ikke hente rapporter');
    }

    if (requestId !== reportsRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }

    weatherState.observations = payload.reports.length
      ? payload.reports
      : [{ icon: '🌤️', time: 'Ingen data', reporter: payload.filtered ? `Ingen rapporter nær ${weatherState.location.name}` : 'Ingen observasjoner ennå', condition: payload.filtered ? 'Utvid søket eller send første lokale rapport' : 'Send den første rapporten', temp: 0 }];
  } catch (error) {
    if (requestId !== reportsRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }
    console.warn('Kunne ikke hente DB-observasjoner:', error);
    weatherState.observations = [{ icon: '⚠️', time: 'API utilgjengelig', reporter: 'Kunne ikke hente observasjoner', condition: 'Sjekk DB/API-oppsett', temp: 0 }];
  }

  if (requestId !== reportsRequestSequence || locationKey !== getActiveLocationKey()) {
    return;
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
  const initialLat = Number.isFinite(Number(weatherState.location.lat)) ? Number(weatherState.location.lat) : 58.1504;
  const initialLon = Number.isFinite(Number(weatherState.location.lon)) ? Number(weatherState.location.lon) : 7.9470;
  const initialZoom = weatherState.location.source === 'user' ? 11 : 9;

  weatherState.map = L.map(mapEl, {
    zoomControl: true,
    attributionControl: false,
  }).setView([initialLat, initialLon], initialZoom);

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
    const fallbackLat = Number.isFinite(Number(weatherState.location.lat)) ? Number(weatherState.location.lat) : 58.1504;
    const fallbackLon = Number.isFinite(Number(weatherState.location.lon)) ? Number(weatherState.location.lon) : 7.9470;
    weatherState.map.setView([fallbackLat, fallbackLon], weatherState.location.source === 'user' ? 11 : 9);
    if (status) {
      status.textContent = weatherState.location.source === 'user' || weatherState.location.source === 'search'
        ? `Ingen rapporter med koordinater nær ${weatherState.location.name} ennå.`
        : 'Ingen rapporter med koordinater ennå. Listen under viser lokale observasjoner uten kartpunkt.';
    }
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

function bindLocationSearch() {
  const form = document.querySelector('#location-search-form');
  const input = document.querySelector('#location-search-input');
  const status = document.querySelector('#location-search-status');
  if (!form || !input) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = input.value.trim();
    if (query.length < 2) {
      showToast('Skriv minst to tegn for å søke etter sted.');
      return;
    }

    const button = form.querySelector('button[type="submit"]');
    const originalText = button?.textContent || '';
    if (button) {
      button.disabled = true;
      button.textContent = 'Søker…';
    }
    if (status) status.textContent = `Søker etter ${query}...`;

    try {
      const response = await fetch(`api/geocode.php?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
        cache: 'force-cache',
      });
      const payload = await response.json();
      if (!response.ok || !payload.success || !Array.isArray(payload.results) || !payload.results.length) {
        throw new Error(payload.message || 'Fant ikke stedet.');
      }

      await setActiveLocation({ ...payload.results[0], source: 'search', searchQuery: query });
      if (status) status.textContent = `Viser MET-data og rapporter nær ${weatherState.location.name}.`;
      showToast(`Viser vær for ${weatherState.location.name}.`);
    } catch (error) {
      if (status) status.textContent = 'Fant ikke stedet. Prøv et mer presist stedsnavn.';
      showToast(error.message || 'Kunne ikke søke etter sted.');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = originalText || 'Søk';
      }
    }
  });
}

async function setActiveLocation(location) {
  weatherState.location = {
    id: location.id || `loc-${Number(location.lat).toFixed(4)}-${Number(location.lon).toFixed(4)}`,
    name: location.name || 'Valgt sted',
    lat: Number(Number(location.lat).toFixed(6)),
    lon: Number(Number(location.lon).toFixed(6)),
    source: location.source || 'search',
    searchQuery: location.searchQuery || '',
  };
  renderCurrentWeather();
  renderWeatherMeta();
  await Promise.all([loadWeather(), loadReports()]);
}

function bindWeatherLocation() {
  const button = document.querySelector('#weather-position-button');
  if (!button) return;

  button.addEventListener('click', async () => {
    await useBrowserLocationForWeather({ prompt: true });
  });
}

async function useBrowserLocationForWeather({ prompt = false } = {}) {
  if (!navigator.geolocation) {
    if (prompt) showToast('Posisjon støttes ikke i denne nettleseren.');
    return false;
  }

  if (!prompt && navigator.permissions?.query) {
    try {
      const permission = await navigator.permissions.query({ name: 'geolocation' });
      if (permission.state !== 'granted') return false;
    } catch (error) {
      return false;
    }
  } else if (!prompt) {
    return false;
  }

  const button = document.querySelector('#weather-position-button');
  const value = button?.querySelector('.weather-meta-value');
  const originalText = value?.textContent || '';
  if (value) value.textContent = 'Henter…';
  if (button) button.disabled = true;

  try {
    const position = await requestCurrentPosition({ enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    await setActiveLocation({
      id: `pos-${position.coords.latitude.toFixed(4)}-${position.coords.longitude.toFixed(4)}`,
      name: 'Din posisjon',
      lat: Number(position.coords.latitude.toFixed(6)),
      lon: Number(position.coords.longitude.toFixed(6)),
      source: 'user',
    });
    if (prompt) showToast('Varsel oppdatert for din posisjon.');
    return true;
  } catch (error) {
    if (prompt) showToast('Kunne ikke hente posisjon.');
    return false;
  } finally {
    if (button) button.disabled = false;
    if (value) value.textContent = originalText || 'Bruk min';
  }
}

function requestCurrentPosition(options) {
  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(resolve, reject, options);
  });
}

function bindReportForm() {
  const form = document.querySelector('#report-form');
  if (!form || form.dataset.vaervaktBound === 'true') return;
  form.dataset.vaervaktBound = 'true';

  let isSubmitting = false;
  const submitButton = form.querySelector('button[type="submit"]');

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
    if (isSubmitting) return;

    const data = Object.fromEntries(new FormData(form).entries());
    if (!data.lat && !data.lon && weatherState.location.source === 'user') {
      data.lat = String(weatherState.location.lat);
      data.lon = String(weatherState.location.lon);
    }
    isSubmitting = true;
    form.setAttribute('aria-busy', 'true');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.dataset.originalText = submitButton.textContent || '';
      submitButton.textContent = 'Sender…';
    }

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
    } finally {
      isSubmitting = false;
      form.removeAttribute('aria-busy');
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = submitButton.dataset.originalText || 'Send værrapport';
        delete submitButton.dataset.originalText;
      }
    }
  });
}

function getReportConditionForCurrentWeather() {
  const condition = String(weatherState.current?.condition || '').toLowerCase();
  if (condition.includes('regn') || condition.includes('byge')) return 'Regn / Byger';
  if (condition.includes('snø') || condition.includes('sludd')) return 'Snø / Sludd';
  if (condition.includes('tåke')) return 'Tåke';
  if (condition.includes('vind')) return 'Kraftig vind';
  if (condition.includes('sky') || condition.includes('overskyet')) return 'Overskyet';
  return 'Sol / Klart';
}

function bindBathingReportShortcut() {
  const button = document.querySelector('#bathing-report-button');
  if (!button) return;

  button.addEventListener('click', () => {
    setActiveNavItem('report');

    const location = document.querySelector('#report-location');
    const temp = document.querySelector('#report-temp');
    const condition = document.querySelector('#report-condition');

    if (location && !location.value) location.value = weatherState.location.name || '';
    if (temp && !temp.value) temp.value = String(Math.round(Number(weatherState.current?.temperature) || 0));
    if (condition && !condition.value) condition.value = getReportConditionForCurrentWeather();

    showToast('Rapporter hvordan været faktisk er ved badeplassen.');
    window.setTimeout(() => document.querySelector('#report-name')?.focus(), 180);
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

function trackVisit() {
  const payload = JSON.stringify({
    path: window.location.pathname || '/',
    at: new Date().toISOString(),
  });

  if (navigator.sendBeacon) {
    navigator.sendBeacon('api/visit.php', new Blob([payload], { type: 'application/json' }));
    return;
  }

  fetch('api/visit.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: payload,
    keepalive: true,
  }).catch(() => {});
}

async function initApp() {
  updateClock();
  trackVisit();
  renderCurrentWeather();
  drawRainChart();
  drawTemperatureChart();
  renderForecast();
  renderWeatherMeta();
  renderWeatherExperience();
  renderObservations();
  renderNavigation();
  bindLocationSearch();
  bindWeatherLocation();
  bindReportForm();
  bindBathingReportShortcut();
  bindFavorites();
  renderFavorites();
  await loadWeather();
  const usedPosition = await useBrowserLocationForWeather();
  if (!usedPosition) {
    await loadReports();
  }
  window.setInterval(updateClock, 30_000);
}

window.VaervaktApp = { showToast };
document.addEventListener('DOMContentLoaded', () => {
  initApp().catch((error) => {
    console.error('Kunne ikke starte Værvakt:', error);
    showToast('Kunne ikke starte appen helt riktig.');
  });
});

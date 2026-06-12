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
  hubPosts: [],
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
const hubIdentityStorageKey = 'vaervakt_hub_identity';
const hubVotesStorageKey = 'vaervakt_hub_votes';
let weatherRequestSequence = 0;
let reportsRequestSequence = 0;
let bathingSearchRequestSequence = 0;
let hubRequestSequence = 0;
let hubSort = 'new';

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

function formatBathingResultTime(value) {
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  return new Intl.DateTimeFormat('nb-NO', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function createBathingResultCard(result) {
  const card = document.createElement('article');
  card.className = 'bathing-result-card';

  const body = document.createElement('div');
  const name = document.createElement('strong');
  name.className = 'bathing-result-name';
  name.textContent = result.name || 'Badeplass';

  const meta = document.createElement('p');
  meta.className = 'bathing-result-meta';
  const metaParts = [
    result.municipality,
    Number.isFinite(Number(result.distanceKm)) ? `${Number(result.distanceKm).toFixed(1).replace('.', ',')} km unna` : '',
    formatBathingResultTime(result.time),
    result.heatedWater ? 'oppvarmet vann' : '',
  ].filter(Boolean);
  meta.textContent = metaParts.join(' · ') || result.credit || 'Badetemperaturer levert av Yr';

  body.append(name, meta);

  const temp = document.createElement('strong');
  temp.className = 'bathing-result-temp';
  temp.textContent = Number.isFinite(Number(result.waterTemperature))
    ? `${Number(result.waterTemperature).toFixed(1).replace('.', ',')}°`
    : '--';

  const button = document.createElement('button');
  button.className = 'bathing-result-action';
  button.type = 'button';
  button.textContent = 'Vis været her';
  button.dataset.bathingSelect = 'true';
  button.dataset.id = result.id || '';
  button.dataset.name = [result.name, result.municipality].filter(Boolean).join(', ');
  button.dataset.lat = String(result.lat ?? '');
  button.dataset.lon = String(result.lon ?? '');

  card.append(body, temp, button);
  return card;
}

function renderBathingSearchResults(results, message = '') {
  const list = document.querySelector('#bathing-search-results');
  if (!list) return;

  list.classList.remove('hidden-view');
  if (!Array.isArray(results) || results.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = message || 'Fant ingen ferske badetemperaturer for dette søket.';
    list.replaceChildren(empty);
    return;
  }

  list.replaceChildren(...results.map(createBathingResultCard));
}

function bindBathingTemperatureSearch() {
  const form = document.querySelector('#bathing-search-form');
  const input = document.querySelector('#bathing-search-input');
  const status = document.querySelector('#bathing-search-status');
  const results = document.querySelector('#bathing-search-results');
  if (!form || !input) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = input.value.trim();
    if (query.length < 2) {
      showToast('Skriv minst to tegn for å søke etter badeplass.');
      return;
    }

    const requestId = ++bathingSearchRequestSequence;
    const button = form.querySelector('button[type="submit"]');
    const originalText = button?.textContent || 'Søk';
    if (button) {
      button.disabled = true;
      button.textContent = 'Søker...';
    }
    if (status) status.textContent = `Søker etter badetemperatur for ${query}...`;

    try {
      const response = await fetch(`api/bathing-search.php?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const payload = await response.json().catch(() => ({}));
      if (requestId !== bathingSearchRequestSequence) return;
      if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Kunne ikke søke etter badetemperatur.');
      }

      renderBathingSearchResults(payload.results || [], payload.message || '');
      if (status) {
        const count = Array.isArray(payload.results) ? payload.results.length : 0;
        status.textContent = count > 0
          ? `${count} treff fra Yr. Trykk “Vis været her” hvis du vil bruke badeplassen i hele appen.`
          : (payload.message || 'Fant ingen ferske badetemperaturer.');
      }
    } catch (error) {
      renderBathingSearchResults([], error.message || 'Kunne ikke søke etter badetemperatur.');
      if (status) status.textContent = error.message || 'Kunne ikke søke etter badetemperatur.';
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = originalText;
      }
    }
  });

  results?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const button = target.closest('[data-bathing-select]');
    if (!(button instanceof HTMLElement)) return;

    const lat = Number(button.dataset.lat);
    const lon = Number(button.dataset.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
      showToast('Dette treffet mangler koordinater.');
      return;
    }

    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Henter...';
    await setActiveLocation({
      id: button.dataset.id || `bath-${lat.toFixed(4)}-${lon.toFixed(4)}`,
      name: button.dataset.name || 'Badeplass',
      lat,
      lon,
      source: 'search',
      searchQuery: button.dataset.name || '',
    });
    document.querySelector('#bathing-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    showToast(`Viser badevær for ${button.dataset.name || 'badeplassen'}.`);
    button.removeAttribute('aria-busy');
    button.textContent = 'Vis været her';
  });
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
  renderHomePlaces();
  renderHubProfileState();
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
  if (activeId === 'settings') renderHubProfileState();
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

function createHomePlaceCard(place, isCurrent = false) {
  const item = document.createElement('article');
  item.className = 'home-place-row';

  const icon = document.createElement('span');
  icon.className = 'home-place-star';
  icon.setAttribute('aria-hidden', 'true');
  icon.textContent = isCurrent ? '●' : '☆';

  const body = document.createElement('div');
  const title = document.createElement('strong');
  title.className = 'home-place-title';
  title.textContent = place.name || place.location || 'Lagret sted';
  const meta = document.createElement('span');
  meta.className = 'home-place-meta';
  meta.textContent = isCurrent ? 'Aktivt sted' : (place.label || 'Lagret favoritt');
  body.append(title, meta);

  if (isCurrent) {
    const weather = document.createElement('span');
    weather.className = 'home-place-weather';
    weather.textContent = `${weatherState.current.icon || '🌤️'} ${Math.round(Number(weatherState.current.temperature) || 0)}°`;
    item.append(icon, body, weather);
    return item;
  }

  const button = document.createElement('button');
  button.className = 'home-place-action';
  button.type = 'button';
  button.textContent = 'Vis';
  button.dataset.homePlace = 'true';
  button.dataset.id = place.id || '';
  button.dataset.name = place.name || place.location || 'Lagret sted';
  button.dataset.lat = String(place.lat ?? '');
  button.dataset.lon = String(place.lon ?? '');
  button.dataset.source = place.source || 'search';
  button.dataset.searchQuery = place.searchQuery || place.name || '';
  item.append(icon, body, button);
  return item;
}

function renderHomePlaces() {
  const list = document.querySelector('#home-places-list');
  if (!list) return;

  const favorites = getFavorites()
    .filter((favorite) => favorite.id !== weatherState.location.id)
    .slice(0, 4);
  const rows = [
    createHomePlaceCard(weatherState.location, true),
    ...favorites.map((favorite) => createHomePlaceCard(favorite, false)),
  ];

  if (favorites.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = 'Trykk Lagre på aktivt sted for å bygge din egen lille værforside.';
    rows.push(empty);
  }

  list.replaceChildren(...rows);
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
  renderHomePlaces();
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

  const homePlacesList = document.querySelector('#home-places-list');
  if (homePlacesList) {
    homePlacesList.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;

      const button = target.closest('[data-home-place]');
      if (!(button instanceof HTMLElement)) return;

      const lat = Number(button.dataset.lat);
      const lon = Number(button.dataset.lon);
      if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        showToast('Favoritten mangler koordinater.');
        return;
      }

      button.textContent = 'Henter';
      await setActiveLocation({
        id: button.dataset.id || `fav-${lat.toFixed(4)}-${lon.toFixed(4)}`,
        name: button.dataset.name || 'Lagret sted',
        lat,
        lon,
        source: button.dataset.source || 'search',
        searchQuery: button.dataset.searchQuery || button.dataset.name || '',
      });
      button.textContent = 'Vis';
      showToast(`Viser ${button.dataset.name || 'favoritten'}.`);
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
  await Promise.all([loadWeather(), loadReports(), loadHubPosts()]);
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

function getSuggestedBathingPlaceName() {
  const yrLocation = String(weatherState.bathing?.waterTemperatureLocation || '').split(',')[0]?.trim();
  if (yrLocation) return yrLocation;

  return String(weatherState.location.name || '')
    .replace(/,\s*(NO|Norge|Norway)$/i, '')
    .trim();
}

function prefillBathingPlaceForm(form) {
  const placeName = form.querySelector('#bathing-place-name');
  const lat = form.querySelector('#bathing-place-lat');
  const lon = form.querySelector('#bathing-place-lon');

  if (placeName && !placeName.value) placeName.value = getSuggestedBathingPlaceName();
  if (lat && !lat.value && Number.isFinite(Number(weatherState.location.lat))) {
    lat.value = Number(weatherState.location.lat).toFixed(6);
  }
  if (lon && !lon.value && Number.isFinite(Number(weatherState.location.lon))) {
    lon.value = Number(weatherState.location.lon).toFixed(6);
  }
}

function bindBathingPlaceSuggestion() {
  const button = document.querySelector('#bathing-report-button');
  const form = document.querySelector('#bathing-place-form');
  if (!button || !form) return;

  button.addEventListener('click', () => {
    const isOpening = form.classList.contains('hidden-view');
    form.classList.toggle('hidden-view', !isOpening);
    button.setAttribute('aria-expanded', String(isOpening));
    button.textContent = isOpening ? 'Lukk badeplassforslag' : 'Foreslå badeplass til Yr';

    if (!isOpening) return;

    prefillBathingPlaceForm(form);
    window.setTimeout(() => form.querySelector('#bathing-place-name')?.focus(), 120);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = form.querySelector('button[type="submit"]');
    const data = Object.fromEntries(new FormData(form).entries());

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.dataset.originalText = submitButton.textContent || 'Send badeplassforslag';
      submitButton.textContent = 'Sender...';
    }

    try {
      const response = await fetch('api/bathing-place.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Kunne ikke lagre badeplassforslaget.');
      }

      navigator.vibrate?.(12);
      showToast(payload.message || 'Badeplassforslaget er sendt.');
      form.reset();
      form.classList.add('hidden-view');
      button.setAttribute('aria-expanded', 'false');
      button.textContent = 'Foreslå badeplass til Yr';
    } catch (error) {
      showToast(error.message || 'Kunne ikke sende badeplassforslaget.');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = submitButton.dataset.originalText || 'Send badeplassforslag';
        delete submitButton.dataset.originalText;
      }
    }
  });
}

function getHubIdentity() {
  try {
    const identity = JSON.parse(localStorage.getItem(hubIdentityStorageKey) || 'null');
    if (!identity || !identity.user || !identity.authToken) return null;
    if (!Number.isFinite(Number(identity.user.id))) return null;
    return identity;
  } catch {
    return null;
  }
}

function saveHubIdentity(identity) {
  localStorage.setItem(hubIdentityStorageKey, JSON.stringify(identity));
  renderHubProfileState();
}

function clearHubIdentity() {
  localStorage.removeItem(hubIdentityStorageKey);
  renderHubProfileState();
}

function getHubVoteMemory() {
  try {
    const votes = JSON.parse(localStorage.getItem(hubVotesStorageKey) || '{}');
    return votes && typeof votes === 'object' ? votes : {};
  } catch {
    return {};
  }
}

function saveHubVoteMemory(votes) {
  localStorage.setItem(hubVotesStorageKey, JSON.stringify(votes));
}

function getHubAuthPayload() {
  const identity = getHubIdentity();
  if (!identity) return null;
  return {
    userId: Number(identity.user.id),
    authToken: identity.authToken,
  };
}

function getHubCategoryLabel(category) {
  const labels = {
    general: 'Innlegg',
    question: 'Spørsmål',
    warning: 'Varsel',
    tip: 'Tips',
    photo: 'Observasjon',
  };
  return labels[category] || labels.general;
}

function renderHubProfileState() {
  const identity = getHubIdentity();
  const status = document.querySelector('#hub-profile-status');
  const activeProfile = document.querySelector('#hub-active-profile');
  const activeProfileNote = document.querySelector('#hub-active-profile-note');
  const logoutButton = document.querySelector('#hub-logout-button');
  const location = document.querySelector('#hub-post-location');
  const settingsButton = document.querySelector('#settings-open-button');

  if (status) {
    status.textContent = identity
      ? `Poster som ${identity.user.displayName}.`
      : 'Logg inn med navn og PIN under Profil for å poste og stemme.';
  }
  if (activeProfile) {
    activeProfile.textContent = identity ? identity.user.displayName : 'Ikke logget inn';
  }
  if (activeProfileNote) {
    activeProfileNote.textContent = identity
      ? 'Denne profilen er kun lagret lokalt i nettleseren med en server-token.'
      : 'Opprett eller logg inn for å poste og stemme i Værhub.';
  }
  if (logoutButton) {
    logoutButton.classList.toggle('hidden-view', !identity);
  }
  if (settingsButton) {
    settingsButton.textContent = identity ? identity.user.displayName : 'Profil';
  }
  if (location) {
    const temp = Math.round(Number(weatherState.current?.temperature ?? 0));
    const condition = weatherState.current?.condition || 'Ukjent vær';
    location.textContent = `Kobles til ${weatherState.location.name} · ${condition} · ${temp}°`;
  }
}

function createHubPostElement(post) {
  const votes = getHubVoteMemory();
  const myVote = Number(votes[String(post.id)] || 0);
  const card = document.createElement('article');
  card.className = 'hub-post-card';
  card.dataset.postId = String(post.id);
  const temperature = Number.isFinite(Number(post.temperature)) ? `${Math.round(Number(post.temperature))}°` : '';
  const weather = [post.weatherCondition, temperature].filter(Boolean).join(' · ');
  card.innerHTML = `
    <div class="hub-vote-box">
      <button class="hub-vote-button" type="button" data-hub-vote="1" data-active="${myVote === 1}" aria-label="Stem opp">▲</button>
      <strong class="hub-score">${Number(post.score) || 0}</strong>
      <button class="hub-vote-button" type="button" data-hub-vote="-1" data-active="${myVote === -1}" aria-label="Stem ned">▼</button>
    </div>
    <div class="hub-post-body">
      <div class="hub-post-meta">
        <span>${escapeHtml(getHubCategoryLabel(post.category))}</span>
        <span>${escapeHtml(post.location || 'Ukjent sted')}</span>
        <span>${escapeHtml(post.time || 'Nylig')}</span>
      </div>
      <h3 class="hub-post-title">${escapeHtml(post.title)}</h3>
      <p class="hub-post-text">${escapeHtml(post.body)}</p>
      <div class="hub-post-footer">
        <span>av ${escapeHtml(post.displayName || 'Anonym værvakt')}</span>
        <span>${escapeHtml(weather || 'Ingen værsnapshot')}</span>
      </div>
    </div>
  `;
  return card;
}

function renderHubFeed() {
  const feed = document.querySelector('#hub-feed');
  if (!feed) return;

  if (!weatherState.hubPosts.length) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = `Ingen lokale hub-innlegg nær ${weatherState.location.name} ennå.`;
    feed.replaceChildren(empty);
    return;
  }

  feed.replaceChildren(...weatherState.hubPosts.map(createHubPostElement));
}

async function loadHubPosts() {
  const requestId = ++hubRequestSequence;
  const locationKey = getActiveLocationKey();

  try {
    const params = new URLSearchParams({ limit: '20', sort: hubSort });
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

    const response = await fetch(`api/hub.php?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Kunne ikke hente Værhub');
    }
    if (requestId !== hubRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }
    weatherState.hubPosts = Array.isArray(payload.posts) ? payload.posts : [];
  } catch (error) {
    if (requestId !== hubRequestSequence || locationKey !== getActiveLocationKey()) {
      return;
    }
    console.warn('Kunne ikke hente Værhub:', error);
    weatherState.hubPosts = [];
  }

  renderHubFeed();
}

function bindHub() {
  const form = document.querySelector('#hub-post-form');
  const refreshButton = document.querySelector('#hub-refresh-button');
  const feed = document.querySelector('#hub-feed');

  if (refreshButton) {
    refreshButton.addEventListener('click', async () => {
      refreshButton.disabled = true;
      await loadHubPosts();
      refreshButton.disabled = false;
      showToast('Værhub oppdatert.');
    });
  }

  document.querySelectorAll('[data-hub-sort]').forEach((button) => {
    button.addEventListener('click', async () => {
      hubSort = button.dataset.hubSort || 'new';
      document.querySelectorAll('[data-hub-sort]').forEach((item) => {
        item.dataset.active = String(item === button);
      });
      await loadHubPosts();
    });
  });

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const auth = getHubAuthPayload();
      if (!auth) {
        showToast('Logg inn med navn og PIN først.');
        setActiveNavItem('settings');
        return;
      }

      const submitButton = form.querySelector('button[type="submit"]');
      const data = Object.fromEntries(new FormData(form).entries());
      const payload = {
        ...auth,
        title: data.title,
        category: data.category || 'general',
        body: data.body,
        location: weatherState.location.name,
        lat: weatherState.location.lat,
        lon: weatherState.location.lon,
        weatherCondition: weatherState.current?.condition || '',
        temperature: weatherState.current?.temperature ?? null,
      };

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.dataset.originalText = submitButton.textContent || 'Publiser';
        submitButton.textContent = 'Publiserer...';
      }

      try {
        const response = await fetch('api/hub.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) {
          throw new Error(result.message || 'Kunne ikke publisere innlegget.');
        }
        navigator.vibrate?.(10);
        form.reset();
        showToast(result.duplicate ? 'Innlegget var allerede sendt.' : 'Innlegg publisert i Værhub.');
        await loadHubPosts();
      } catch (error) {
        showToast(error.message || 'Kunne ikke publisere innlegget.');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = submitButton.dataset.originalText || 'Publiser i Værhub';
          delete submitButton.dataset.originalText;
        }
      }
    });
  }

  if (feed) {
    feed.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const button = target.closest('[data-hub-vote]');
      if (!(button instanceof HTMLElement)) return;

      const auth = getHubAuthPayload();
      if (!auth) {
        showToast('Logg inn med navn og PIN for å stemme.');
        setActiveNavItem('settings');
        return;
      }

      const card = button.closest('[data-post-id]');
      const postId = Number(card?.dataset.postId);
      const vote = Number(button.dataset.hubVote);
      if (!Number.isFinite(postId) || ![-1, 1].includes(vote)) return;

      const votes = getHubVoteMemory();
      const currentVote = Number(votes[String(postId)] || 0);
      const nextVote = currentVote === vote ? 0 : vote;

      button.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch('api/hub.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ ...auth, action: 'vote', postId, vote: nextVote }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) {
          throw new Error(result.message || 'Kunne ikke lagre stemmen.');
        }
        if (nextVote === 0) {
          delete votes[String(postId)];
        } else {
          votes[String(postId)] = nextVote;
        }
        saveHubVoteMemory(votes);

        const post = weatherState.hubPosts.find((item) => Number(item.id) === postId);
        if (post) {
          post.score = result.score;
          post.voteCount = result.voteCount;
        }
        renderHubFeed();
      } catch (error) {
        showToast(error.message || 'Kunne ikke stemme akkurat nå.');
      } finally {
        button.removeAttribute('aria-busy');
      }
    });
  }
}

function bindSettings() {
  const openButton = document.querySelector('#settings-open-button');
  const closeButton = document.querySelector('#settings-close-button');
  const logoutButton = document.querySelector('#hub-logout-button');
  const form = document.querySelector('#hub-auth-form');

  if (openButton) {
    openButton.addEventListener('click', () => setActiveNavItem('settings'));
  }
  if (closeButton) {
    closeButton.addEventListener('click', () => setActiveNavItem('home'));
  }
  if (logoutButton) {
    logoutButton.addEventListener('click', () => {
      clearHubIdentity();
      showToast('Du er logget ut lokalt.');
    });
  }

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitter = event.submitter instanceof HTMLElement ? event.submitter : document.activeElement;
      const action = submitter instanceof HTMLElement && submitter.dataset.hubAuthAction === 'register' ? 'register' : 'login';
      const data = Object.fromEntries(new FormData(form).entries());
      const buttons = Array.from(form.querySelectorAll('button'));

      buttons.forEach((button) => { button.disabled = true; });
      try {
        const response = await fetch('api/hub.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ action, displayName: data.displayName, pin: data.pin }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success || !result.user || !result.authToken) {
          throw new Error(result.message || 'Kunne ikke logge inn.');
        }
        saveHubIdentity({ user: result.user, authToken: result.authToken });
        form.reset();
        showToast(result.message || 'Profilen er klar.');
        setActiveNavItem('home');
      } catch (error) {
        showToast(error.message || 'Kunne ikke lagre profilen.');
      } finally {
        buttons.forEach((button) => { button.disabled = false; });
      }
    });
  }

  renderHubProfileState();
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
  bindBathingTemperatureSearch();
  bindBathingPlaceSuggestion();
  bindHub();
  bindSettings();
  bindFavorites();
  renderFavorites();
  renderHomePlaces();
  await loadWeather();
  const usedPosition = await useBrowserLocationForWeather();
  if (!usedPosition) {
    await Promise.all([loadReports(), loadHubPosts()]);
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

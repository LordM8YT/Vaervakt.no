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
    { icon: '☀️', time: 'omtrent 3 timer siden', reporter: 'Patrick i Kristiansand', condition: 'Sol / Klart', temp: 19 },
    { icon: '☁️', time: 'omtrent 3 timer siden', reporter: 'Marte i Lillesand', condition: 'Overskyet', temp: 15 },
    { icon: '🌧️', time: 'omtrent 3 timer siden', reporter: 'Lars i Mandal', condition: 'Regn / Byger', temp: 12 },
  ],
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

  list.replaceChildren(...weatherState.observations.map((item) => {
    const row = document.createElement('article');
    row.className = 'obs-item';
    row.innerHTML = `
      <span class="obs-icon" aria-hidden="true">${item.icon}</span>
      <div class="obs-body">
        <p class="obs-time">${item.time}</p>
        <p class="obs-name">${item.reporter}</p>
        <p class="obs-condition">${item.condition}</p>
      </div>
      <span class="obs-temp">${item.temp}°</span>
    `;
    return row;
  }));
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
  document.querySelectorAll('[data-nav-item]').forEach((button) => {
    const isActive = button.dataset.navItem === activeId;
    button.dataset.active = String(isActive);
    const icon = button.querySelector('svg');
    if (icon) icon.setAttribute('stroke-width', isActive ? '2.2' : '1.7');
  });
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
  window.setInterval(updateClock, 30_000);
}

window.VaervaktApp = { showToast };
document.addEventListener('DOMContentLoaded', initApp);

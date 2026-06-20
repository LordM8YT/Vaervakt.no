const state = {
  location: {
    id: 'kristiansand',
    name: 'Kristiansand',
    lat: 58.1504,
    lon: 7.9470,
    source: 'default',
    searchQuery: 'Kristiansand',
  },
  weather: null,
  reports: [],
  hubPosts: [],
  hubSort: 'new',
};

const profileKey = 'vaervakt_v2_profile';
const voteKey = 'vaervakt_v2_votes';

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));
}

function showToast(message) {
  const toast = $('#toast');
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add('is-visible');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
}

function profile() {
  try {
    const stored = JSON.parse(localStorage.getItem(profileKey) || 'null');
    return stored?.user?.id && stored?.token ? stored : null;
  } catch {
    return null;
  }
}

function authPayload() {
  const current = profile();
  return current ? { userId: current.user.id, token: current.token } : null;
}

function setProfile(nextProfile) {
  if (nextProfile) {
    localStorage.setItem(profileKey, JSON.stringify(nextProfile));
  } else {
    localStorage.removeItem(profileKey);
  }
  renderProfile();
}

function voteMemory() {
  try {
    return JSON.parse(localStorage.getItem(voteKey) || '{}') || {};
  } catch {
    return {};
  }
}

function setVoteMemory(votes) {
  localStorage.setItem(voteKey, JSON.stringify(votes));
}

function setView(viewName) {
  $$('[data-view]').forEach((view) => view.classList.toggle('is-active', view.dataset.view === viewName));
  $$('[data-nav]').forEach((button) => button.classList.toggle('is-active', button.dataset.nav === viewName));
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (viewName === 'settings') renderProfile();
}

function updateClock() {
  const clock = $('#clock');
  if (clock) {
    clock.textContent = new Intl.DateTimeFormat('nb-NO', { hour: '2-digit', minute: '2-digit' }).format(new Date());
  }
}

function renderWeather() {
  const weather = state.weather;
  $('#place-name').textContent = state.location.name;
  $('#weather-icon').textContent = weather?.current?.icon || '🌤️';
  $('#temperature').textContent = weather ? `${Math.round(Number(weather.current.temperature))}°` : '--°';
  $('#condition').textContent = weather?.current?.condition || 'Henter værdata fra MET';
  $('#weather-detail').textContent = weather?.summary?.detail || 'Lokalt varsel og rapporter lastes inn.';
  $('#feels-like').textContent = weather ? `${Math.round(Number(weather.current.feelsLike))}°` : '--°';
  $('#wind').textContent = weather ? `${Number(weather.current.windSpeed).toFixed(1).replace('.', ',')} m/s` : '--';
  const nextRain = weather?.rain?.[0]?.amount;
  $('#rain').textContent = Number.isFinite(Number(nextRain)) ? `${Number(nextRain).toFixed(1).replace('.', ',')} mm` : '--';

  const hourly = $('#hourly');
  if (hourly) {
    const items = weather?.hourly?.length ? weather.hourly : [];
    hourly.innerHTML = items.map((item) => `
      <article class="hour-card">
        <span class="muted">${escapeHtml(item.hour)}</span>
        <div aria-hidden="true">${escapeHtml(item.icon)}</div>
        <strong>${Math.round(Number(item.temp))}°</strong>
        <small class="muted">${Number(item.windSpeed || 0).toFixed(1).replace('.', ',')} m/s</small>
      </article>
    `).join('');
  }

  const bath = weather?.bathing;
  $('#bath-score').textContent = bath ? `${Math.round(Number(bath.score))}%` : '--';
  $('#bath-text').textContent = bath
    ? `${bath.label}. ${bath.waterTemperature ? `Vann: ${Number(bath.waterTemperature).toFixed(1).replace('.', ',')}° ved ${bath.waterTemperatureLocation}.` : bath.source}`
    : 'Beregnes fra MET-varsel og Yr badetemperatur når tilgjengelig.';
}

function renderReports() {
  const list = $('#reports-list');
  if (!list) return;
  if (!state.reports.length) {
    list.innerHTML = `<p class="muted">Ingen lokale rapporter rundt ${escapeHtml(state.location.name)} ennå.</p>`;
    return;
  }
  list.innerHTML = state.reports.map((report) => `
    <article class="report-card">
      <div class="report-top">
        <h3>${escapeHtml(report.icon)} ${escapeHtml(report.condition)}</h3>
        <strong>${Math.round(Number(report.temp))}°</strong>
      </div>
      <p class="muted">${escapeHtml(report.reporter)} · ${escapeHtml(report.location)} · ${escapeHtml(report.time)}</p>
    </article>
  `).join('');
}

function renderHub() {
  const note = $('#hub-profile-note');
  const currentProfile = profile();
  if (note) note.textContent = currentProfile ? `Poster som ${currentProfile.user.displayName}.` : 'Logg inn i Profil for å poste og stemme.';

  const list = $('#hub-list');
  if (!list) return;
  if (!state.hubPosts.length) {
    list.innerHTML = `<p class="muted">Ingen hub-innlegg rundt ${escapeHtml(state.location.name)} ennå.</p>`;
    return;
  }
  const votes = voteMemory();
  list.innerHTML = state.hubPosts.map((post) => {
    const myVote = Number(votes[String(post.id)] || 0);
    return `
      <article class="hub-card" data-post-id="${post.id}">
        <div class="hub-top">
          <h3>${escapeHtml(post.title)}</h3>
          <span class="score-pill">${Number(post.score) || 0}</span>
        </div>
        <p>${escapeHtml(post.body)}</p>
        <p class="muted">${escapeHtml(post.displayName)} · ${escapeHtml(post.location)} · ${escapeHtml(post.time)}</p>
        <div class="hub-actions">
          <button class="ghost-button" data-vote="1" data-active="${myVote === 1}" type="button">Stem opp</button>
          <button class="ghost-button" data-vote="-1" data-active="${myVote === -1}" type="button">Stem ned</button>
        </div>
      </article>
    `;
  }).join('');
}

function renderProfile() {
  const current = profile();
  $('#profile-name').textContent = current ? current.user.displayName : 'Ikke logget inn';
  const logout = $('#logout-profile');
  if (logout) logout.hidden = !current;
  $('#open-settings').textContent = current ? current.user.displayName : 'Profil';
  renderHub();
}

function reportParams() {
  const params = new URLSearchParams({ limit: '20' });
  params.set('lat', String(state.location.lat));
  params.set('lon', String(state.location.lon));
  params.set('radiusKm', state.location.source === 'user' ? '15' : '25');
  params.set('location', state.location.searchQuery || state.location.name);
  return params;
}

async function getJson(url, options = {}) {
  const response = await fetch(url, { headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || !payload.success) throw new Error(payload.message || 'Noe gikk galt.');
  return payload;
}

async function loadWeather() {
  try {
    state.weather = await getJson(`/api/weather.php?lat=${encodeURIComponent(state.location.lat)}&lon=${encodeURIComponent(state.location.lon)}`);
  } catch (error) {
    showToast(error.message || 'Kunne ikke hente værdata.');
  }
  renderWeather();
}

async function loadReports() {
  try {
    const payload = await getJson(`/api/reports.php?${reportParams().toString()}`);
    state.reports = payload.reports || [];
  } catch (error) {
    state.reports = [];
    showToast(error.message || 'Kunne ikke hente rapporter.');
  }
  renderReports();
}

async function loadHub() {
  try {
    const params = reportParams();
    params.set('sort', state.hubSort);
    const payload = await getJson(`/api/hub.php?${params.toString()}`);
    state.hubPosts = payload.posts || [];
  } catch (error) {
    state.hubPosts = [];
    showToast(error.message || 'Kunne ikke hente Værhub.');
  }
  renderHub();
}

async function refreshAll() {
  renderWeather();
  await Promise.all([loadWeather(), loadReports(), loadHub()]);
}

async function setLocation(location) {
  state.location = {
    id: location.id || `loc-${Number(location.lat).toFixed(4)}-${Number(location.lon).toFixed(4)}`,
    name: location.name || 'Valgt sted',
    lat: Number(location.lat),
    lon: Number(location.lon),
    source: location.source || 'search',
    searchQuery: location.searchQuery || location.name || '',
  };
  showToast(`Viser ${state.location.name}.`);
  await refreshAll();
}

function bindNavigation() {
  $$('[data-nav]').forEach((button) => {
    button.addEventListener('click', () => setView(button.dataset.nav));
  });
  $('#open-settings')?.addEventListener('click', () => setView('settings'));
}

function bindLocation() {
  $('#place-search')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = $('#place-query').value.trim();
    if (query.length < 2) return showToast('Skriv minst to tegn.');
    try {
      const payload = await getJson(`/api/geocode.php?q=${encodeURIComponent(query)}`);
      if (!payload.results?.length) throw new Error('Fant ikke stedet.');
      await setLocation(payload.results[0]);
    } catch (error) {
      showToast(error.message || 'Kunne ikke søke etter sted.');
    }
  });

  $('#use-location')?.addEventListener('click', () => {
    if (!navigator.geolocation) return showToast('Nettleseren støtter ikke posisjon.');
    navigator.geolocation.getCurrentPosition(async (position) => {
      try {
        const payload = await getJson(`/api/geocode.php?lat=${position.coords.latitude}&lon=${position.coords.longitude}`);
        await setLocation(payload.result);
      } catch {
        await setLocation({
          id: `pos-${position.coords.latitude.toFixed(4)}-${position.coords.longitude.toFixed(4)}`,
          name: 'Din posisjon',
          lat: position.coords.latitude,
          lon: position.coords.longitude,
          source: 'user',
        });
      }
    }, () => showToast('Kunne ikke hente posisjon.'), { enableHighAccuracy: true, timeout: 10000 });
  });
}

function bindReports() {
  $('#refresh-reports')?.addEventListener('click', loadReports);
  $('#report-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.location = data.location || state.location.name;
    data.lat = state.location.lat;
    data.lon = state.location.lon;
    try {
      await getJson('/api/reports.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      event.currentTarget.reset();
      navigator.vibrate?.(10);
      showToast('Rapport sendt.');
      await loadReports();
      setView('home');
    } catch (error) {
      showToast(error.message || 'Kunne ikke sende rapport.');
    }
  });
}

function bindHub() {
  $('#refresh-hub')?.addEventListener('click', loadHub);
  $('#hub-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const auth = authPayload();
    if (!auth) {
      showToast('Logg inn i Profil først.');
      setView('settings');
      return;
    }
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      await getJson('/api/hub.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'create',
          ...auth,
          ...data,
          location: state.location.name,
          lat: state.location.lat,
          lon: state.location.lon,
          weatherCondition: state.weather?.current?.condition || '',
          temperature: state.weather?.current?.temperature ?? null,
        }),
      });
      event.currentTarget.reset();
      showToast('Innlegg publisert.');
      await loadHub();
    } catch (error) {
      showToast(error.message || 'Kunne ikke publisere.');
    }
  });

  $('#hub-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-vote]');
    if (!button) return;
    const auth = authPayload();
    if (!auth) {
      showToast('Logg inn i Profil først.');
      setView('settings');
      return;
    }
    const card = button.closest('[data-post-id]');
    const postId = Number(card?.dataset.postId);
    const nextVote = Number(button.dataset.vote);
    const votes = voteMemory();
    const stored = Number(votes[String(postId)] || 0);
    const vote = stored === nextVote ? 0 : nextVote;
    try {
      const payload = await getJson('/api/hub.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'vote', ...auth, postId, vote }),
      });
      if (vote === 0) delete votes[String(postId)];
      else votes[String(postId)] = vote;
      setVoteMemory(votes);
      const post = state.hubPosts.find((item) => Number(item.id) === postId);
      if (post) {
        post.score = payload.score;
        post.voteCount = payload.voteCount;
      }
      renderHub();
    } catch (error) {
      showToast(error.message || 'Kunne ikke stemme.');
    }
  });
}

function bindProfile() {
  $('#logout-profile')?.addEventListener('click', () => {
    setProfile(null);
    showToast('Du er logget ut lokalt.');
  });
  $('#profile-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitter = event.submitter;
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      const payload = await getJson('/api/hub.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: submitter?.value || 'login', ...data }),
      });
      setProfile({ user: payload.user, token: payload.token });
      event.currentTarget.reset();
      showToast(payload.message || 'Profilen er klar.');
      setView('home');
    } catch (error) {
      showToast(error.message || 'Kunne ikke logge inn.');
    }
  });
}

async function init() {
  updateClock();
  window.setInterval(updateClock, 30000);
  bindNavigation();
  bindLocation();
  bindReports();
  bindHub();
  bindProfile();
  renderProfile();
  await refreshAll();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js').catch(() => {});
  }
}

document.addEventListener('DOMContentLoaded', () => {
  init().catch((error) => {
    console.error(error);
    showToast('Kunne ikke starte Værvakt helt riktig.');
  });
});

(function () {
  const favoriteKey = "vaervakt_favorite_locations";
  const autoLocationKey = "vaervakt_auto_location";
  const autoReloadKey = "vaervakt_auto_location_reload";
  const defaultLat = 58.1467;
  const defaultLon = 7.9956;
  const state = {
    location: null,
    autoLocation: null,
    reports: [],
    freshness: null,
    historyOpen: false,
    historyReports: [],
    isHistoryLoading: false,
    weather: null,
    weatherLocationId: "",
    isWeatherLoading: false,
    autoLocateAttempted: false,
    pushStatus: "idle",
  };

  const originalFetch = window.fetch ? window.fetch.bind(window) : null;
  if (!originalFetch) return;

  try {
    localStorage.removeItem(autoLocationKey);
    sessionStorage.removeItem(autoReloadKey);
  } catch {
    // Storage can be blocked in strict/private browser modes.
  }

  function readAutoLocation() {
    return null;
  }

  function setAutoLocation(location) {
    state.autoLocation = null;
  }

  function parseUrl(input) {
    try {
      const raw = typeof input === "string" ? input : input && input.url;
      return raw ? new URL(raw, window.location.origin) : null;
    } catch {
      return null;
    }
  }

  function isReportsUrl(url) {
    return url && url.pathname.endsWith("/api/reports.php");
  }

  function isWeatherUrl(url) {
    return url && url.pathname.endsWith("/api/weather.php");
  }

  function shouldPatchLocation(url) {
    return false;
  }

  function patchLocationUrl(url) {
    return url;
  }

  function patchRequestBody(input, init, url) {
    return { input, init };
  }

  function rememberLocation(url) {
    const lat = Number(url.searchParams.get("lat"));
    const lon = Number(url.searchParams.get("lon"));
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    const auto = state.autoLocation;
    state.location = {
      lat: auto && isDefaultCoords(lat, lon) ? auto.lat : lat,
      lon: auto && isDefaultCoords(lat, lon) ? auto.lon : lon,
      name: auto && isDefaultCoords(lat, lon) ? auto.name : (url.searchParams.get("location") || readPlaceName() || "Valgt sted"),
      radiusKm: Number(url.searchParams.get("radiusKm")) || 35,
    };
  }

  function readPlaceName() {
    const headings = Array.from(document.querySelectorAll("h2,h3"));
    const weatherHeading = headings.find((item) => /,\s*(NO|Norge)$/i.test(item.textContent.trim()));
    return weatherHeading ? weatherHeading.textContent.trim() : "";
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[char]));
  }

  function findLocalSection() {
    const headings = Array.from(document.querySelectorAll("h2,h3,h4,h5"));
    const heading = headings.find((item) => item.textContent.trim() === "Lokalt fra Værvakt");
    if (!heading) return null;
    let current = heading.parentElement;
    while (current && current !== document.body) {
      if (current.querySelector && current.querySelector("form")) return current;
      current = current.parentElement;
    }
    return heading.parentElement;
  }

  function favorites() {
    try {
      const items = JSON.parse(localStorage.getItem(favoriteKey) || "[]");
      return Array.isArray(items)
        ? items.filter((item) => item && item.name && Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon))).slice(0, 6)
        : [];
    } catch {
      return [];
    }
  }

  function setFavorites(items) {
    localStorage.setItem(favoriteKey, JSON.stringify(items.slice(0, 6)));
  }

  function currentFavoriteId() {
    if (!state.location) return "";
    return `${Number(state.location.lat).toFixed(5)},${Number(state.location.lon).toFixed(5)}`;
  }

  function saveFavorite() {
    if (!state.location) return;
    const id = currentFavoriteId();
    const items = favorites().filter((item) => item.id !== id);
    items.unshift({ id, name: state.location.name || readPlaceName() || "Valgt sted", lat: state.location.lat, lon: state.location.lon });
    setFavorites(items);
    renderUtilityPanel();
  }

  function useFavorite(item) {
    const input = document.querySelector("input[id$='-input'], input[type='text']");
    if (input) {
      input.focus();
      input.value = item.name;
      input.dispatchEvent(new Event("input", { bubbles: true }));
    }
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function uvLevel(value) {
    const uv = Number(value);
    if (!Number.isFinite(uv)) return { label: "Ukjent", className: "vv-uv-low" };
    if (uv >= 8) return { label: "Svært høy", className: "vv-uv-high" };
    if (uv >= 6) return { label: "Høy", className: "vv-uv-high" };
    if (uv >= 3) return { label: "Moderat", className: "vv-uv-mid" };
    return { label: "Lav", className: "vv-uv-low" };
  }

  function visibleText(element) {
    return String(element && element.textContent || "").trim().replace(/\s+/g, " ");
  }

  function isDefaultLocationVisible() {
    return Array.from(document.querySelectorAll("h2,h3,p,span"))
      .some((element) => visibleText(element).includes("Kristiansand"));
  }

  function isDefaultCoords(lat, lon) {
    return Math.abs(Number(lat) - defaultLat) < 0.02 && Math.abs(Number(lon) - defaultLon) < 0.02;
  }

  function applyAutoLocationToPage() {
    return;
  }

  async function autoLocate() {
    state.autoLocateAttempted = true;
    return;
  }

  function renderUtilityPanel() {
    const section = findLocalSection();
    if (!section || !state.location) return;

    let panel = document.getElementById("vv-live-tools-panel");
    if (!panel) {
      panel = document.createElement("div");
      panel.id = "vv-live-tools-panel";
      section.appendChild(panel);
    }
    ensureStyles();

    const hiddenOld = Number(state.freshness && state.freshness.hiddenOldReports) || 0;
    const favs = favorites();
    const isSaved = favs.some((item) => item.id === currentFavoriteId());
    const uvValue = state.weather && state.weather.current ? Number(state.weather.current.uvIndex) : NaN;
    const uv = uvLevel(uvValue);
    const uvText = Number.isFinite(uvValue) ? uvValue.toFixed(1).replace(".", ",") : "--";

    const panelHtml = `
      <div class="vv-tools-head">
        <div>
          <p class="vv-tools-title">Rapportliste</p>
          <p class="vv-tools-subtitle">${hiddenOld ? `${hiddenOld} eldre rapporter ligger i historikken` : `Ferske rapporter for ${escapeHtml(state.location.name)}`}</p>
        </div>
        <div class="vv-tools-actions">
          <button class="vv-tools-button" type="button" data-vv-favorite data-active="${isSaved ? "true" : "false"}">${isSaved ? "Lagret" : "Lagre sted"}</button>
          ${hiddenOld ? `<button class="vv-tools-button" type="button" data-vv-history data-active="${state.historyOpen ? "true" : "false"}">${state.historyOpen ? "Skjul historikk" : "Vis historikk"}</button>` : ""}
        </div>
      </div>
      <div class="vv-tools-row">
        <div>
          <p class="vv-tools-title">UV nå</p>
          <p class="vv-tools-subtitle">Fra MET for valgt sted</p>
        </div>
        <div class="vv-uv-pill ${uv.className}"><strong>${escapeHtml(uvText)}</strong><span>${escapeHtml(uv.label)}</span></div>
      </div>
      ${favs.length ? `<div class="vv-fav-list">${favs.map((item) => `<button class="vv-fav-chip" type="button" data-vv-use-favorite="${escapeHtml(item.id)}">${escapeHtml(item.name)}</button>`).join("")}</div>` : ""}
      ${state.historyOpen ? renderHistoryList() : ""}
    `;

    if (panel.__vvLastHtml !== panelHtml) {
      panel.innerHTML = panelHtml;
      panel.__vvLastHtml = panelHtml;
    }
    const favoriteButton = panel.querySelector("[data-vv-favorite]");
    if (favoriteButton) favoriteButton.onclick = saveFavorite;
    const historyButton = panel.querySelector("[data-vv-history]");
    if (historyButton) historyButton.onclick = toggleHistory;
    panel.querySelectorAll("[data-vv-use-favorite]").forEach((button) => {
      button.onclick = () => {
        const item = favorites().find((fav) => fav.id === button.getAttribute("data-vv-use-favorite"));
        if (item) useFavorite(item);
      };
    });
  }

  function ensureStyles() {
    if (document.getElementById("vv-live-tools-style")) return;
    const style = document.createElement("style");
    style.id = "vv-live-tools-style";
    style.textContent = `
      #vv-live-tools-panel {
        margin-top: 14px;
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 18px;
        overflow: hidden;
        background: linear-gradient(180deg, rgba(9,16,36,.72), rgba(7,12,27,.88));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
      }
      .vv-tools-head, .vv-tools-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        padding: 13px 14px 11px;
      }
      .vv-tools-head + .vv-tools-row { border-top: 1px solid rgba(255,255,255,.07); }
      .vv-tools-title { color: white; font: 800 .92rem Poppins, system-ui, sans-serif; margin: 0; }
      .vv-tools-subtitle { color: rgba(255,255,255,.48); font: 500 .74rem Poppins, system-ui, sans-serif; margin: 4px 0 0; }
      .vv-tools-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
      .vv-tools-button, .vv-fav-chip {
        appearance: none;
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 999px;
        background: rgba(255,255,255,.06);
        color: rgba(255,255,255,.84);
        cursor: pointer;
        font: 800 .72rem Poppins, system-ui, sans-serif;
        padding: 7px 10px;
      }
      .vv-tools-button:hover, .vv-fav-chip:hover { background: rgba(255,255,255,.1); }
      .vv-tools-button[data-active="true"] { background: #38bdf8; border-color: rgba(186,230,253,.62); color: #06111f; }
      .vv-uv-pill {
        border-radius: 16px;
        min-width: 74px;
        padding: 9px 10px;
        text-align: center;
        border: 1px solid rgba(255,255,255,.12);
        background: rgba(255,255,255,.06);
      }
      .vv-uv-pill strong { display: block; color: white; font: 900 1.15rem Poppins, system-ui, sans-serif; line-height: 1; }
      .vv-uv-pill span { display: block; margin-top: 4px; color: rgba(255,255,255,.62); font: 700 .65rem Poppins, system-ui, sans-serif; }
      .vv-uv-mid { background: rgba(251,191,36,.15); border-color: rgba(251,191,36,.28); }
      .vv-uv-high { background: rgba(251,113,133,.16); border-color: rgba(251,113,133,.34); }
      .vv-fav-list, .vv-history-list { border-top: 1px solid rgba(255,255,255,.07); padding: 10px 14px 14px; display: flex; gap: 8px; flex-wrap: wrap; }
      .vv-history-list { display: grid; }
      .vv-history-item { display: flex; justify-content: space-between; gap: 10px; color: rgba(255,255,255,.78); font: 600 .76rem Poppins, system-ui, sans-serif; }
      .vv-history-item small { color: rgba(255,255,255,.46); display: block; font-weight: 500; margin-top: 2px; }
    `;
    document.head.appendChild(style);
  }

  async function loadWeatherForTools() {
    if (!state.location || state.isWeatherLoading) return;
    const id = currentFavoriteId();
    if (state.weather && state.weatherLocationId === id) return;
    state.isWeatherLoading = true;
    try {
      const params = new URLSearchParams({ lat: String(state.location.lat), lon: String(state.location.lon) });
      state.weather = await originalFetch(`/api/weather.php?${params.toString()}`).then((response) => response.json());
      state.weatherLocationId = id;
    } catch {
      state.weather = null;
      state.weatherLocationId = "";
    } finally {
      state.isWeatherLoading = false;
      renderUtilityPanel();
    }
  }

  function renderHistoryList() {
    if (state.isHistoryLoading) {
      return `<div class="vv-history-list"><div class="vv-history-item">Henter historikk...</div></div>`;
    }
    if (!state.historyReports.length) {
      return `<div class="vv-history-list"><div class="vv-history-item">Ingen historikk funnet for dette området.</div></div>`;
    }
    return `
      <div class="vv-history-list">
        ${state.historyReports.slice(0, 8).map((report) => `
          <div class="vv-history-item">
            <span>${escapeHtml(report.icon || "")} ${escapeHtml(report.condition)}<small>${escapeHtml(report.reporter)} · ${escapeHtml(report.location)} · ${escapeHtml(report.time)}</small></span>
            <strong>${escapeHtml(report.temp)}°</strong>
          </div>
        `).join("")}
      </div>
    `;
  }

  async function toggleHistory() {
    state.historyOpen = !state.historyOpen;
    if (state.historyOpen && !state.historyReports.length && state.location) {
      state.isHistoryLoading = true;
      renderUtilityPanel();
      const params = new URLSearchParams({
        limit: "20",
        lat: String(state.location.lat),
        lon: String(state.location.lon),
        radiusKm: String(state.location.radiusKm || 35),
        location: state.location.name,
        freshness: "all",
      });
      try {
        const payload = await originalFetch(`/api/reports.php?${params.toString()}`).then((response) => response.json());
        state.historyReports = Array.isArray(payload.reports) ? payload.reports : [];
      } catch {
        state.historyReports = [];
      } finally {
        state.isHistoryLoading = false;
      }
    }
    renderUtilityPanel();
    improveEmptyReportCopy();
  }

  function improveEmptyReportCopy() {
    const localSection = findLocalSection();
    if (!localSection) return;
    const hiddenOld = Number(state.freshness && state.freshness.hiddenOldReports) || 0;
    const textNodes = Array.from(localSection.querySelectorAll("p,span,div"));
    const staleEmpty = textNodes.find((node) => node.textContent.trim() === "Ingen lokale rapporter her enda. Bli førstemann.");
    if (staleEmpty && hiddenOld > 0) {
      staleEmpty.textContent = `Ingen ferske rapporter siste 7 dager. ${hiddenOld} eldre rapporter ligger i historikken.`;
    }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    const rawData = window.atob(base64);
    return Uint8Array.from(Array.from(rawData).map((char) => char.charCodeAt(0)));
  }

  async function registerPush() {
    if (state.pushStatus !== "idle" || !("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) return;
    state.pushStatus = "checking";
    try {
      const config = await originalFetch("/api/config.php").then((response) => response.json());
      const vapidPublicKey = config && config.pwa && config.pwa.vapidPublicKey;
      if (!vapidPublicKey) return;
      const registration = await navigator.serviceWorker.register("/service-worker.js", { updateViaCache: "none" });
      registration.update().catch(() => {});
      const existing = await registration.pushManager.getSubscription();
      if (existing || Notification.permission === "denied") return;
      if (Notification.permission !== "granted") {
        state.pushStatus = "ready";
        return;
      }
      await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) });
      state.pushStatus = "subscribed";
    } catch {
      state.pushStatus = "failed";
    }
  }

  function scheduleRender() {
    window.clearTimeout(scheduleRender.timer);
    scheduleRender.timer = window.setTimeout(() => {
      improveEmptyReportCopy();
      renderUtilityPanel();
      loadWeatherForTools();
      registerPush();
    }, 80);
  }

  window.fetch = async function vvEnhancedFetch(input, init) {
    const url = parseUrl(input);
    const patchedUrl = patchLocationUrl(url);
    const patchedInput = patchedUrl !== url && typeof input === "string" ? patchedUrl.toString() : input;
    const patchedRequest = patchRequestBody(patchedInput, init, patchedUrl);
    const response = await originalFetch(patchedRequest.input, patchedRequest.init);
    if (isWeatherUrl(patchedUrl)) {
      response.clone().json().then((payload) => {
        state.weather = payload || null;
        scheduleRender();
      }).catch(() => {});
    }
    if (isReportsUrl(patchedUrl) && (!init || !init.method || String(init.method).toUpperCase() === "GET")) {
      rememberLocation(patchedUrl);
      response.clone().json().then((payload) => {
        if (patchedUrl.searchParams.get("freshness") === "all") return;
        state.reports = Array.isArray(payload.reports) ? payload.reports : [];
        state.freshness = payload.freshness || null;
        scheduleRender();
      }).catch(() => {});
    }
    return response;
  };

  const observer = new MutationObserver(scheduleRender);
  window.addEventListener("DOMContentLoaded", () => {
    observer.observe(document.body, { childList: true, subtree: true });
    scheduleRender();
  });
}());

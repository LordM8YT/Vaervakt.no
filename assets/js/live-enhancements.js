(function () {
  const state = {
    location: null,
    reports: [],
    freshness: null,
    mapOpen: false,
    historyOpen: false,
    historyReports: [],
    isHistoryLoading: false,
  };

  const originalFetch = window.fetch ? window.fetch.bind(window) : null;
  if (!originalFetch) return;

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

  function rememberLocation(url) {
    const lat = Number(url.searchParams.get("lat"));
    const lon = Number(url.searchParams.get("lon"));
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    state.location = {
      lat,
      lon,
      name: url.searchParams.get("location") || "Valgt sted",
      radiusKm: Number(url.searchParams.get("radiusKm")) || 35,
    };
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

  function reportHasCoords(report) {
    return Number.isFinite(Number(report.lat)) && Number.isFinite(Number(report.lon));
  }

  function mapBounds(center, reports) {
    const coords = [
      [center.lat, center.lon],
      ...reports.filter(reportHasCoords).map((report) => [Number(report.lat), Number(report.lon)]),
    ];
    const lats = coords.map((coord) => coord[0]);
    const lons = coords.map((coord) => coord[1]);
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);
    const minLon = Math.min(...lons);
    const maxLon = Math.max(...lons);
    const latPad = Math.max(0.012, (maxLat - minLat) * 0.45);
    const lonPad = Math.max(0.018, (maxLon - minLon) * 0.45);
    return {
      south: minLat - latPad,
      north: maxLat + latPad,
      west: minLon - lonPad,
      east: maxLon + lonPad,
    };
  }

  function mapUrl(bounds, center) {
    const bbox = [bounds.west, bounds.south, bounds.east, bounds.north]
      .map((value) => value.toFixed(6))
      .join(",");
    return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${center.lat.toFixed(6)},${center.lon.toFixed(6)}`;
  }

  function osmPointUrl(lat, lon) {
    return `https://www.openstreetmap.org/?mlat=${Number(lat).toFixed(6)}&mlon=${Number(lon).toFixed(6)}#map=15/${Number(lat).toFixed(6)}/${Number(lon).toFixed(6)}`;
  }

  function renderMapPanel() {
    const section = findLocalSection();
    if (!section || !state.location) return;

    let panel = document.getElementById("vv-live-map-panel");
    if (!panel) {
      panel = document.createElement("div");
      panel.id = "vv-live-map-panel";
      section.appendChild(panel);
    }

    const reports = state.historyOpen && state.historyReports.length ? state.historyReports : state.reports;
    const reportsWithCoords = reports.filter(reportHasCoords);
    const bounds = mapBounds(state.location, reportsWithCoords);
    const hiddenOld = Number(state.freshness && state.freshness.hiddenOldReports) || 0;
    const subtitle = reportsWithCoords.length
      ? `${reportsWithCoords.length} rapportpunkt med koordinater`
      : `Viser valgt posisjon for ${state.location.name}`;

    const panelHtml = `
      <style>
        #vv-live-map-panel {
          margin-top: 14px;
          border: 1px solid rgba(255,255,255,.08);
          border-radius: 18px;
          overflow: hidden;
          background: linear-gradient(180deg, rgba(9,16,36,.72), rgba(7,12,27,.88));
          box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        }
        .vv-map-head {
          display: flex;
          justify-content: space-between;
          gap: 12px;
          align-items: center;
          padding: 13px 14px 11px;
        }
        .vv-map-title {
          color: white;
          font: 800 .92rem Poppins, system-ui, sans-serif;
          margin: 0;
        }
        .vv-map-subtitle {
          color: rgba(255,255,255,.48);
          font: 500 .74rem Poppins, system-ui, sans-serif;
          margin: 4px 0 0;
        }
        .vv-map-actions {
          display: flex;
          gap: 8px;
          flex-wrap: wrap;
          justify-content: flex-end;
        }
        .vv-map-button {
          appearance: none;
          border: 1px solid rgba(255,255,255,.12);
          border-radius: 999px;
          background: rgba(255,255,255,.06);
          color: rgba(255,255,255,.84);
          cursor: pointer;
          font: 800 .72rem Poppins, system-ui, sans-serif;
          padding: 7px 10px;
        }
        .vv-map-button:hover { background: rgba(255,255,255,.1); }
        .vv-map-button[data-active="true"] {
          background: #38bdf8;
          border-color: rgba(186,230,253,.62);
          color: #06111f;
        }
        .vv-map-wrap {
          height: clamp(220px, 42vw, 330px);
          background: #0b1224;
          border-top: 1px solid rgba(255,255,255,.07);
        }
        .vv-map-wrap iframe {
          width: 100%;
          height: 100%;
          border: 0;
          display: block;
          filter: saturate(.78) contrast(.92) brightness(.82);
        }
        .vv-map-empty {
          padding: 0 14px 14px;
          color: rgba(255,255,255,.62);
          font: 500 .78rem Poppins, system-ui, sans-serif;
        }
        .vv-map-report-list {
          border-top: 1px solid rgba(255,255,255,.07);
          display: grid;
          gap: 8px;
          padding: 10px 14px 14px;
        }
        .vv-map-report {
          align-items: center;
          display: flex;
          gap: 10px;
          justify-content: space-between;
        }
        .vv-map-report span {
          color: rgba(255,255,255,.78);
          font: 700 .76rem Poppins, system-ui, sans-serif;
          min-width: 0;
        }
        .vv-map-report small {
          color: rgba(255,255,255,.46);
          display: block;
          font-weight: 500;
          margin-top: 2px;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
        .vv-map-report a {
          border: 1px solid rgba(255,255,255,.12);
          border-radius: 999px;
          color: rgba(255,255,255,.82);
          flex: 0 0 auto;
          font: 800 .68rem Poppins, system-ui, sans-serif;
          padding: 6px 9px;
          text-decoration: none;
        }
        .vv-map-report a:hover { background: rgba(255,255,255,.08); }
        .vv-history-list {
          border-top: 1px solid rgba(255,255,255,.07);
          padding: 10px 14px 14px;
          display: grid;
          gap: 8px;
        }
        .vv-history-item {
          display: flex;
          justify-content: space-between;
          gap: 10px;
          color: rgba(255,255,255,.78);
          font: 600 .76rem Poppins, system-ui, sans-serif;
        }
        .vv-history-item small {
          color: rgba(255,255,255,.46);
          display: block;
          font-weight: 500;
          margin-top: 2px;
        }
      </style>
      <div class="vv-map-head">
        <div>
          <p class="vv-map-title">Kart og rapporthistorikk</p>
          <p class="vv-map-subtitle">${escapeHtml(subtitle)}${hiddenOld ? ` · ${hiddenOld} eldre skjult` : ""}</p>
        </div>
        <div class="vv-map-actions">
          <button class="vv-map-button" type="button" data-vv-map data-active="${state.mapOpen ? "true" : "false"}">${state.mapOpen ? "Skjul kart" : "Vis kart"}</button>
          ${hiddenOld ? `<button class="vv-map-button" type="button" data-vv-history data-active="${state.historyOpen ? "true" : "false"}">${state.historyOpen ? "Skjul historikk" : "Vis historikk"}</button>` : ""}
        </div>
      </div>
      ${state.mapOpen ? `
        <div class="vv-map-wrap">
          <iframe title="Kart over lokale værrapporter" loading="lazy" src="${mapUrl(bounds, state.location)}"></iframe>
        </div>
        ${reportsWithCoords.length ? renderMapReportList(reportsWithCoords) : `<div class="vv-map-empty">Ingen ferske rapporter med koordinater her akkurat nå. Kartet viser valgt sted, og nye rapporter dukker opp her.</div>`}
      ` : ""}
      ${state.historyOpen ? renderHistoryList() : ""}
    `;

    if (panel.__vvLastHtml !== panelHtml) {
      panel.innerHTML = panelHtml;
      panel.__vvLastHtml = panelHtml;
    }
    const mapButton = panel.querySelector("[data-vv-map]");
    if (mapButton) mapButton.onclick = toggleMap;
    const button = panel.querySelector("[data-vv-history]");
    if (button) button.onclick = toggleHistory;
  }

  function toggleMap() {
    state.mapOpen = !state.mapOpen;
    renderMapPanel();
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

  function renderMapReportList(reports) {
    return `
      <div class="vv-map-report-list">
        ${reports.slice(0, 6).map((report) => `
          <div class="vv-map-report">
            <span>${escapeHtml(report.icon || "")} ${escapeHtml(report.condition)}<small>${escapeHtml(report.location)} · ${escapeHtml(report.time)}</small></span>
            <a href="${osmPointUrl(report.lat, report.lon)}" target="_blank" rel="noreferrer">Åpne</a>
          </div>
        `).join("")}
      </div>
    `;
  }

  async function toggleHistory() {
    state.historyOpen = !state.historyOpen;
    if (state.historyOpen && !state.historyReports.length && state.location) {
      state.isHistoryLoading = true;
      renderMapPanel();
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
    renderMapPanel();
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

  function scheduleRender() {
    window.clearTimeout(scheduleRender.timer);
    scheduleRender.timer = window.setTimeout(() => {
      improveEmptyReportCopy();
      renderMapPanel();
    }, 80);
  }

  window.fetch = async function vvEnhancedFetch(input, init) {
    const url = parseUrl(input);
    const response = await originalFetch(input, init);
    if (isReportsUrl(url) && (!init || !init.method || String(init.method).toUpperCase() === "GET")) {
      rememberLocation(url);
      response.clone().json().then((payload) => {
        if (url.searchParams.get("freshness") === "all") return;
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

(function () {
  const pageKey = "vaervakt_active_page";
  const pages = [
    ["weather", "/"],
    ["local", "/lokalt/"],
    ["bath", "/bad/"],
    ["glimpses", "/glimt/"],
  ];
  const labels = {
    weather: "Vær",
    local: "Lokalt",
    bath: "Bad",
    glimpses: "Glimt",
  };
  const routePages = {
    "/": "weather",
    "/index.html": "weather",
    "/lokalt/": "local",
    "/lokalt/index.html": "local",
    "/bad/": "bath",
    "/bad/index.html": "bath",
    "/glimt/": "glimpses",
    "/glimt/index.html": "glimpses",
  };

  let activePage = routePage() || localStorage.getItem(pageKey) || "weather";

  function routePage() {
    const fromQuery = new URLSearchParams(window.location.search).get("page");
    if (labels[fromQuery]) return fromQuery;
    return routePages[window.location.pathname] || "";
  }

  function text(element) {
    return String((element && element.textContent) || "").trim().replace(/\s+/g, " ");
  }

  function ensureStyle() {
    if (document.getElementById("vv-app-tabs-style")) return;
    const style = document.createElement("style");
    style.id = "vv-app-tabs-style";
    style.textContent = `
      #vv-app-nav {
        position: sticky;
        top: 6px;
        z-index: 40;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 6px;
        width: calc(100% - 32px);
        max-width: 1116px;
        margin: 10px auto 14px;
        padding: 7px;
        border: 1px solid rgba(255,255,255,.1);
        border-radius: 999px;
        background: rgba(2,6,23,.78);
        box-shadow: 0 16px 36px rgba(2,6,23,.28);
        backdrop-filter: blur(18px);
      }
      .vv-app-tab {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: rgba(255,255,255,.62);
        cursor: pointer;
        font: 900 .73rem Poppins, system-ui, sans-serif;
        text-decoration: none;
      }
      .vv-app-tab[data-active="true"] {
        background: #38bdf8;
        color: #06111f;
      }
      [data-vv-page-hidden="true"],
      [data-vv-community-hidden="true"],
      [data-vv-community-child-hidden="true"] { display: none !important; }
    `;
    document.head.appendChild(style);
  }

  function sectionByHeading(label) {
    const headings = Array.from(document.querySelectorAll("h2,h3,h4,h5"));
    const heading = headings.find((item) => text(item) === label);
    if (!heading) return null;

    let current = heading.parentElement;
    while (current && current !== document.body) {
      const controls = current.querySelectorAll("button,input,textarea,select,form,img").length;
      if (controls > 0 && text(current).length > 40) return current;
      current = current.parentElement;
    }
    return heading.parentElement;
  }

  function localSection() {
    const heading = Array.from(document.querySelectorAll("h2,h3,h4,h5"))
      .find((item) => text(item) === "Lokalt fra Værvakt");
    if (!heading) return null;

    let current = heading.parentElement;
    while (current && current !== document.body) {
      if (current.querySelector && current.querySelector("form")) return current;
      current = current.parentElement;
    }
    return heading.parentElement;
  }

  function sections() {
    return {
      local: localSection(),
      bath: sectionByHeading("Badetemperatur"),
      glimpses: sectionByHeading("Værglimt"),
    };
  }

  function closestCommonCommunity(sectionsByPage) {
    const found = Object.values(sectionsByPage).filter(Boolean);
    if (!found.length) return null;
    const section = found[0];
    const stack = section.parentElement;
    if (!stack) return null;
    return stack.parentElement || stack;
  }

  function applyCommunityMode(sectionsByPage) {
    const community = closestCommonCommunity(sectionsByPage);
    if (!community) return;

    community.setAttribute("data-vv-community", "true");
    community.setAttribute("data-vv-community-hidden", "false");

    const activeSection = sectionsByPage[activePage];
    const stack = (activeSection && activeSection.parentElement) || Object.values(sectionsByPage).find(Boolean)?.parentElement;
    if (!stack) return;

    Array.from(stack.children).forEach((child) => {
      const keep = activePage === "weather" ? text(child).includes("Vipps") : child === activeSection;
      child.setAttribute("data-vv-community-child-hidden", keep ? "false" : "true");
    });
  }

  function setPage(page, options) {
    activePage = labels[page] ? page : "weather";
    localStorage.setItem(pageKey, activePage);
    render();
    applyVisibility();
    if (!options || !options.keepScroll) window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function render() {
    ensureStyle();
    const root = document.getElementById("root");
    if (!root) return;

    let nav = document.getElementById("vv-app-nav");
    if (!nav) {
      nav = document.createElement("nav");
      nav.id = "vv-app-nav";
    }
    if (nav.parentElement !== root || root.firstElementChild !== nav) root.prepend(nav);

    const html = pages.map(([key, href]) => {
      const isActive = activePage === key;
      return `<a class="vv-app-tab" href="${href}" data-vv-page="${key}" data-active="${isActive ? "true" : "false"}" aria-current="${isActive ? "page" : "false"}">${labels[key]}</a>`;
    }).join("");
    if (nav.__vvTabsHtml !== html) {
      nav.innerHTML = html;
      nav.__vvTabsHtml = html;
    }
  }

  function applyVisibility() {
    const sectionsByPage = sections();
    Object.entries(sectionsByPage).forEach(([page, section]) => {
      if (!section) return;
      section.setAttribute("data-vv-section", page);
      section.setAttribute("data-vv-page-hidden", activePage === page ? "false" : "true");
    });
    applyCommunityMode(sectionsByPage);
  }

  function tick() {
    window.clearTimeout(tick.timer);
    tick.timer = window.setTimeout(() => {
      render();
      applyVisibility();
    }, 80);
  }

  document.addEventListener("DOMContentLoaded", () => {
    setPage(routePage() || activePage, { keepScroll: true });
    render();
    applyVisibility();
    new MutationObserver(tick).observe(document.body, { childList: true, subtree: true });
  });
}());

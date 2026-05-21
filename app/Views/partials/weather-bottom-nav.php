<nav class="vv-bottom-nav" aria-label="Hovednavigasjon">
    <div class="vv-bottom-nav-inner">
        <button onclick="filterWeather('all')" id="nav-all" class="vv-nav-item is-active">
            <i data-lucide="layout-dashboard" class="w-6 h-6"></i>
            <span>Oversikt</span>
        </button>
        <button type="button" id="nav-report" class="vv-nav-item vv-nav-primary" onclick="openReportSheet()">
            <i data-lucide="plus" class="w-6 h-6"></i>
            <span>Rapport</span>
        </button>
        <button onclick="filterWeather('vann')" id="nav-vann" class="vv-nav-item">
            <i data-lucide="waves" class="w-6 h-6"></i>
            <span>Flom/Snø</span>
        </button>
        <button onclick="openModal('infoModal')" class="vv-nav-item">
            <i data-lucide="shield-check" class="w-6 h-6"></i>
            <span>Info</span>
        </button>
    </div>
</nav>

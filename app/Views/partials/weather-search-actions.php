<section class="vv-section vv-quick-panel" aria-label="Søk og apphandlinger">
    <div class="relative">
        <label for="placeSearch" class="sr-only">Søk på sted eller koordinater</label>
        <input id="placeSearch" type="search" placeholder="Søk på sted eller koordinater" class="vv-input vv-search-input" aria-label="Søk sted">
        <div id="searchResults" class="vv-search-results" style="display:none;"></div>
    </div>

    <div class="vv-action-row">
        <button id="pushBtn" <?= $pushReady ? '' : 'disabled' ?> class="vv-chip <?= $pushReady ? 'vv-chip-primary' : 'vv-chip-disabled' ?>">
            <i data-lucide="bell" class="w-4 h-4"></i>
            <?= $pushReady ? 'Aktiver varsler' : 'Varsler snart' ?>
        </button>
        <button id="installBtn" class="vv-chip hidden">
            <i data-lucide="download" class="w-4 h-4"></i>
            Installer
        </button>
        <?php if ($patchnotes): ?>
            <button type="button" onclick="openModal('patchnotesModal')" class="vv-chip">
                <i data-lucide="sparkles" class="w-4 h-4"></i>
                Nytt
            </button>
        <?php endif; ?>
        <button id="shareBtn" class="vv-chip">
            <i data-lucide="share-2" class="w-4 h-4"></i>
            Del
        </button>
    </div>
    <p id="pushStatus" class="vv-status-line"><?= $pushReady ? 'Push-varsler: ikke abonnert' : 'Push-varsler: kommer snart' ?></p>
</section>

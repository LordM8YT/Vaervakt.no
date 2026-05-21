<button type="button" id="openReportSheetBtn" class="vv-fab" aria-controls="reportSheet" aria-expanded="false">
    <i data-lucide="plus" class="w-7 h-7"></i>
    <span class="sr-only">Ny observasjon</span>
</button>

<div id="reportSheetBackdrop" class="vv-sheet-backdrop" hidden></div>
<section id="reportSheet" class="vv-report-sheet" aria-labelledby="reportSheetTitle" aria-hidden="true">
    <div class="vv-sheet-handle" aria-hidden="true"></div>
    <div class="vv-sheet-header">
        <div>
            <p class="vv-eyebrow">Ny observasjon</p>
            <h2 id="reportSheetTitle">Rapporter været</h2>
        </div>
        <button type="button" id="closeReportSheetBtn" class="vv-icon-button" aria-label="Lukk rapportering">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <form id="reportForm" action="save.php" method="POST" onsubmit="handleSubmit(event)" class="vv-form">
        <label for="userInput" class="sr-only">Ditt navn</label>
        <input id="userInput" type="text" name="user" required placeholder="Ditt navn" class="vv-input" aria-label="Ditt navn" autocomplete="nickname" maxlength="50">
        <div class="sr-only-trap" aria-hidden="true">
            <label for="companyWebsite">Nettside</label>
            <input id="companyWebsite" type="text" name="company_website" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label class="vv-form-label">Værtype</label>
            <div class="vv-condition-grid" data-condition-grid>
                <?php foreach ([['Sol','☀️','Sol'], ['Skyet','☁️','Skyet'], ['Regn','🌧️','Regn'], ['Snø','❄️','Snø'], ['Tåke','🌫️','Tåke'], ['Vind','💨','Vind']] as $option): ?>
                    <button type="button" class="vv-condition" data-weather-value="<?= htmlspecialchars($option[0]) ?>">
                        <span><?= $option[1] ?></span>
                        <strong><?= htmlspecialchars($option[2]) ?></strong>
                    </button>
                <?php endforeach; ?>
            </div>
            <select id="weatherInput" name="weather_type" required class="sr-only" aria-label="Værtype">
                <option value="">-- Velg værtype --</option>
                <option value="Sol">☀️ Sol / Klart</option>
                <option value="Skyet">☁️ Overskyet</option>
                <option value="Regn">🌧️ Regn / Byger</option>
                <option value="Snø">❄️ Snø / Sludd</option>
                <option value="Tåke">🌫️ Tåke</option>
                <option value="Vind">💨 Kraftig vind / Storm</option>
            </select>
        </div>

        <div class="vv-form-grid">
            <label for="locInput" class="sr-only">Sted</label>
            <input id="locInput" type="text" name="loc" required placeholder="Sted eller nabolag" class="vv-input" aria-label="Sted" autocomplete="address-level2" maxlength="100">
            <label for="tempInput" class="sr-only">Temperatur</label>
            <input id="tempInput" type="number" step="0.1" name="temp" required placeholder="°C" class="vv-input vv-temp-input" aria-label="Temperatur" inputmode="decimal">
        </div>

        <div class="vv-location-assist">
            <div class="min-w-0">
                <p class="vv-mini-label">Auto-posisjon</p>
                <p id="locationAssistText" class="status-hint">Trykk for å hente sted automatisk, også utenfor Norge.</p>
            </div>
            <button type="button" id="useLocationBtn" class="vv-chip vv-chip-primary">Bruk min posisjon</button>
        </div>

        <input type="hidden" name="form_started_at" value="">
        <div class="vv-favorites-row">
            <button type="button" id="addFavoriteBtn" class="vv-chip">Legg til favoritt</button>
            <select id="favoritesSelect" class="vv-input vv-favorites-select">
                <option value="">Velg favoritt</option>
            </select>
        </div>
        <input type="hidden" name="lat" id="formLat" value="<?= htmlspecialchars($_GET['lat'] ?? '') ?>">
        <input type="hidden" name="lon" id="formLon" value="<?= htmlspecialchars($_GET['lon'] ?? '') ?>">
        <button id="submitBtn" type="submit" class="vv-submit-button">
            <i data-lucide="send" class="w-5 h-5"></i>
            <span id="submitText">Send værrapport</span>
        </button>
    </form>
</section>

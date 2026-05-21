<?php
$locationLabel = 'Kristiansand, Norge';
$nowLabel = date('H:i');
$weatherIconUrl = "https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/" . rawurlencode((string)$symbol) . ".svg";
$heroTemp = is_numeric($temp_now) ? (int)$temp_now : $temp_now;
$feelsLike = is_numeric($temp_now) ? ((int)$temp_now - 2) : '--';
?>
<section class="vv-section vv-hero" aria-labelledby="currentWeatherTitle">
    <div class="vv-location-row">
        <div>
            <p class="vv-eyebrow">Værvakt live</p>
            <h1 id="currentWeatherTitle" class="vv-location-name"><?= htmlspecialchars($locationLabel) ?></h1>
            <p class="vv-muted">Oppdatert <?= htmlspecialchars($nowLabel) ?> · MET.no + lokale rapporter</p>
        </div>
        <button type="button" class="vv-icon-button" aria-label="Søk sted" onclick="document.getElementById('placeSearch')?.focus()">
            <i data-lucide="search" class="w-5 h-5"></i>
        </button>
    </div>

    <div class="vv-temp-block">
        <div class="vv-temp-copy">
            <div class="vv-temp-line">
                <span id="tempDisplay" class="vv-temp"><?= htmlspecialchars((string)$heroTemp) ?></span>
                <span class="vv-temp-unit">°C</span>
            </div>
            <p class="vv-feels">Føles som <span><?= htmlspecialchars((string)$feelsLike) ?>°C</span></p>
        </div>
        <div id="weatherIcon" class="vv-weather-orb" aria-live="polite">
            <?php if ($api_error): ?>
                <span class="spinner"></span>
            <?php else: ?>
                <img src="<?= htmlspecialchars($weatherIconUrl) ?>" alt="Værikon" class="vv-weather-icon">
            <?php endif; ?>
        </div>
    </div>
</section>

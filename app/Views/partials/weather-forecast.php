<section class="vv-section" aria-labelledby="rainTitle">
    <div class="vv-section-heading">
        <h2 id="rainTitle"><i data-lucide="droplets" class="w-5 h-5"></i> Neste timer</h2>
        <p><?= htmlspecialchars($rainSummary) ?></p>
    </div>
    <div class="vv-rain-chart" aria-label="Nedbørsgraf">
        <?php foreach ($rainBars as $bar): ?>
            <span class="vv-rain-bar" style="height: <?= (int)$bar['height'] ?>%" title="<?= htmlspecialchars($bar['label']) ?>: <?= htmlspecialchars($bar['amount']) ?> mm"></span>
        <?php endforeach; ?>
    </div>
    <div class="vv-chart-axis"><span>Nå</span><span>+6t</span><span>+12t</span></div>
</section>

<section class="vv-section" aria-labelledby="hourlyTitle">
    <div class="vv-section-heading">
        <h2 id="hourlyTitle"><i data-lucide="thermometer" class="w-5 h-5"></i> Temperatur i dag</h2>
        <p>Time-for-time</p>
    </div>
    <div class="vv-hourly-strip">
        <?php foreach ($hourlyForecast as $hour): ?>
            <div class="vv-hour-pill">
                <span><?= htmlspecialchars($hour['label']) ?></span>
                <strong><?= htmlspecialchars($hour['temp']) ?>°</strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="vv-section" aria-labelledby="dailyTitle">
    <div class="vv-section-heading">
        <h2 id="dailyTitle"><i data-lucide="calendar-days" class="w-5 h-5"></i> 5-dagers varsel</h2>
        <p>MET.no</p>
    </div>
    <div class="vv-day-list">
        <?php foreach ($dailyForecast as $day): ?>
            <article class="vv-day-row">
                <div class="vv-day-name"><?= htmlspecialchars($day['label']) ?></div>
                <div class="vv-day-rain"><i data-lucide="droplets" class="w-3.5 h-3.5"></i><?= htmlspecialchars($day['rain']) ?> mm</div>
                <div class="vv-temp-range"><span style="width: <?= (int)$day['range'] ?>%"></span></div>
                <div class="vv-day-temp"><span><?= htmlspecialchars($day['low']) ?>°</span><strong><?= htmlspecialchars($day['high']) ?>°</strong></div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

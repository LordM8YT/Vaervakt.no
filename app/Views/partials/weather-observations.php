<section class="vv-section" aria-labelledby="obsTitle">
    <div class="vv-section-heading">
        <h2 id="obsTitle"><i data-lucide="map-pin" class="w-5 h-5"></i> Lokale rapporter</h2>
        <p id="feedStatusPill" data-tone="neutral" class="feed-status">Live nå</p>
    </div>
    <div id="observationList" class="vv-observation-list">
        <?php
        if (!$latestReports) {
            echo '<p id="noObservationsMsg" class="vv-empty-state">Ingen observasjoner enda...</p>';
        } else {
            foreach ($latestReports as $row) {
                $type = $row['weather_condition'] ?? '';
                $emoji = weatherEmoji($type);
                $timeLabel = formatRelativeTimeLabel($row['created_at'] ?? null);
                echo '<article class="obs-item vv-report-row" data-type="' . htmlspecialchars($type) . '">';
                echo '  <div class="vv-report-main">';
                echo '    <div class="vv-report-emoji" aria-hidden="true">' . $emoji . '</div>';
                echo '    <div class="min-w-0">';
                echo '      <p class="vv-report-user">' . htmlspecialchars($row['username']) . '</p>';
                echo '      <p class="vv-report-location">' . htmlspecialchars($row['location']) . ' · ' . htmlspecialchars($timeLabel) . '</p>';
                echo '    </div>';
                echo '  </div>';
                echo '  <div class="vv-report-temp"><strong>' . round((float)$row['temperature']) . '°</strong><span>' . htmlspecialchars($type) . '</span></div>';
                echo '</article>';
            }
        }
        ?>
        <p id="emptyFilterMsg" class="vv-empty-state" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>
    </div>
    <button id="resetFilter" onclick="filterWeather('all')" class="hidden vv-reset-filter">Gå tilbake til oversikt</button>
</section>

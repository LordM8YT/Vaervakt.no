<section class="vv-section support-card <?= $supportReady ? '' : 'is-pending' ?>" aria-labelledby="supportTitle">
    <div class="vv-two-column">
        <div>
            <p class="vv-eyebrow">Støtt Værvakt</p>
            <h2 id="supportTitle">Hold appen rask og reklamefri</h2>
            <p class="vv-muted mt-2">
                <?= $supportReady
                    ? 'Bidrag hjelper oss med drift, push-varsler og bedre lokal værdekning uten å fylle appen med reklame.'
                    : 'Vipps-lenken er snart klar. Når den er godkjent dukker støtteknappen opp her med én gang.' ?>
            </p>
        </div>
        <?php if ($supportReady): ?>
            <a href="<?= htmlspecialchars($supportUrl) ?>" target="_blank" rel="noopener noreferrer" class="vv-chip vv-chip-primary"><?= htmlspecialchars($supportLabel) ?></a>
        <?php else: ?>
            <button type="button" disabled class="vv-chip vv-chip-disabled">Vipps kommer snart</button>
        <?php endif; ?>
    </div>
</section>

<?php if ($latestPatchnote): ?>
<section class="vv-section" aria-labelledby="latestPatchTitle">
    <div class="vv-two-column">
        <div>
            <p class="vv-eyebrow">Nyeste patchnote · <?= htmlspecialchars(formatPatchnoteDateLabel($latestPatchnote['date'])) ?></p>
            <h2 id="latestPatchTitle"><?= htmlspecialchars($latestPatchnote['title']) ?></h2>
            <?php if (!empty($latestPatchnote['summary'])): ?>
                <p class="vv-muted mt-2"><?= htmlspecialchars($latestPatchnote['summary']) ?></p>
            <?php endif; ?>
        </div>
        <button type="button" onclick="openModal('patchnotesModal')" class="vv-chip">Se alle</button>
    </div>
</section>
<?php endif; ?>

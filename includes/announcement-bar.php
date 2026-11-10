<?php
/* Markup bar pengumuman. Butuh $announcement (hasil ppf_announcement_active) — bila null tidak ada output.
   Tampilan: assets/css/announcement.css. Animasi + tombol ✕: assets/js/announcement.js.
   $announcementPreview = true dipakai dashboard: tanpa tombol ✕ dan tanpa pengecekan "sudah ditutup". */
if (empty($announcement)) return;

$isPreview = !empty($announcementPreview);
$settings  = $announcement['settings'];
$assetBase = $isPreview ? '../assets/' : 'assets/';
?>
<div class="announce" id="announceBar" role="region" aria-label="Announcement"
     data-announce
     data-version="<?= htmlspecialchars($announcement['version']) ?>"
     data-mode="<?= htmlspecialchars($settings['animation']) ?>"
     data-interval="<?= (int) $settings['interval_seconds'] ?>"
     data-speed="<?= (int) $settings['marquee_speed'] ?>">
  <div class="announce__stage">
    <?php foreach ($announcement['items'] as $index => $item): ?>
      <?php
        $label = trim((string) ($item['link_label'] ?? ''));
        $url   = trim((string) ($item['link_url'] ?? ''));
      ?>
      <p class="announce__item<?= $index === 0 ? ' is-active' : '' ?>">
        <span class="announce__msg"><?= htmlspecialchars($item['message']) ?></span>
        <?php if ($url !== ''): ?>
          <a class="announce__link" href="<?= $isPreview ? '#' : htmlspecialchars($url) ?>">
            <?= htmlspecialchars($label !== '' ? $label : 'More') ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg>
          </a>
        <?php endif; ?>
      </p>
    <?php endforeach; ?>
  </div>
  <?php if (!$isPreview): ?>
    <button type="button" class="announce__close" id="announceClose" aria-label="Close announcement">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
    </button>
    <script>(function(){try{var b=document.getElementById('announceBar');if(b&&localStorage.getItem('ppf_announce_closed')===b.dataset.version)b.remove();}catch(e){}})();</script>
  <?php endif; ?>
</div>
<script src="<?= $assetBase ?>js/announcement.js?v=<?= filemtime(__DIR__ . '/../assets/js/announcement.js') ?>" defer></script>

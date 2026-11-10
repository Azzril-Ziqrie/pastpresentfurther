<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/announcement.php';

const ANNOUNCEMENT_SAVED_MESSAGES = [
    'settings' => 'Pengaturan animasi disimpan.',
    'created'  => 'Pengumuman ditambahkan.',
    'updated'  => 'Pengumuman disimpan.',
    'deleted'  => 'Pengumuman dihapus.',
];

/* ── aksi form (POST) ────────────────────────────────── */

/** Jalankan aksi form dan kembalikan kode pesan sukses. Melempar InvalidArgumentException bila input salah. */
function handleAnnouncementAction(PDO $pdo, string $action, array $post): string
{
    $id = (int) ($post['id'] ?? 0);

    switch ($action) {
        case 'save_settings':
            ppf_announcement_save_settings($pdo, $post);
            return 'settings';
        case 'create':
            ppf_announcement_create($pdo, $post);
            return 'created';
        case 'update':
            ppf_announcement_update($pdo, $id, $post);
            return 'updated';
        case 'delete':
            ppf_announcement_delete($pdo, $id);
            return 'deleted';
    }
    throw new InvalidArgumentException('Aksi tidak dikenal.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        try {
            $savedCode = handleAnnouncementAction($pdo, (string) ($_POST['action'] ?? ''), $_POST);
            header('Location: dashboard-announcement.php?saved=' . $savedCode);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

/* ── data untuk tampilan ─────────────────────────────── */
// Tabel dibuat / dimigrasi otomatis di sini bila belum ada (tanpa phpMyAdmin).
$data    = ppf_announcement_load($pdo);
$dbReady = $data !== null;

$items    = $data['items'] ?? [];
$settings = $data['settings'] ?? PPF_ANNOUNCEMENT_DEFAULT_SETTINGS;
$activeItems = array_values(array_filter($items, static fn(array $i): bool => (int) $i['is_active'] === 1));

// Preview memakai markup & skrip yang sama dengan storefront.
$announcement        = $dbReady ? ppf_announcement_active($pdo) : null;
$announcementPreview = true;

$savedMessage = ANNOUNCEMENT_SAVED_MESSAGES[$_GET['saved'] ?? ''] ?? '';
$csrf         = admin_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcement — pastpresentfurther Admin</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="../assets/css/announcement.css?v=<?= filemtime(__DIR__ . '/../assets/css/announcement.css') ?>">
  <style>
    .preview { border: 1px solid #e5e5ea; border-radius: 12px; overflow: hidden; background: #fff; }
    .preview__caption { padding: 8px 14px; font-size: 11px; color: #86868b; border-bottom: 1px solid #e5e5ea; }
    .preview__nav { height: 28px; border-top: 1px solid #e5e5ea; background: #fff; }
    .preview__empty { padding: 14px; font-size: 13px; color: #86868b; }

    .msg-row { display: grid; grid-template-columns: minmax(0, 2.4fr) minmax(0, 1fr) minmax(0, 1.4fr) 80px auto; gap: 10px; align-items: end; padding: 14px 0; border-bottom: 1px solid #e5e5ea; }
    .msg-row:first-child { padding-top: 0; }
    .msg-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .msg-row__field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .msg-row__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .msg-row__active { display: flex; align-items: center; gap: 6px; height: 36px; }
    .is-off-row { opacity: .6; }
    .settings-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .counter { float: right; font-weight: 400; color: #86868b; }
    .btn-danger { color: #9d1f1f !important; }
    @media (max-width: 900px) { .msg-row, .settings-grid { grid-template-columns: 1fr; } }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Announcement'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <section class="hero">
      <div class="hero-left">
        <div class="eyebrow">pastpresentfurther Admin</div>
        <h1 class="headline">Announcement Bar</h1>
        <p class="subcopy">
          Teks di paling atas storefront, di atas navbar (contoh: “Free Shipping with Threshold &nbsp;More →”).
          Isi beberapa pengumuman dan pilih animasinya: slide ke kanan + fading, kedip, fade, atau berjalan terus.
          Pengunjung bisa menutup bar dengan tombol ✕; bila isi atau animasi diubah di sini, bar muncul lagi untuk semua pengunjung.
        </p>
        <div class="hero-actions">
          <a href="../index.php" class="btn-secondary" target="_blank" rel="noopener">View Storefront</a>
          <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
        </div>
      </div>
      <div class="hero-right">
        <div class="eyebrow">Status</div>
        <p class="subcopy" style="margin-top:0;">
          <strong>Pengumuman aktif:</strong> <?= count($activeItems) ?> dari <?= count($items) ?><br>
          <strong>Animasi:</strong> <?= htmlspecialchars(PPF_ANNOUNCEMENT_ANIMATIONS[$settings['animation']]) ?><br>
          <strong>Database:</strong> <?= $dbReady ? 'Siap (tabel dibuat otomatis)' : 'Tidak tersedia' ?>
        </p>
      </div>
    </section>

    <?php if ($savedMessage !== ''): ?><div class="alert success"><?= htmlspecialchars($savedMessage) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$dbReady): ?>
      <div class="alert error">
        Tabel <code>announcements</code> tidak bisa dibuat otomatis (user database kemungkinan tidak punya hak CREATE / ALTER).
        Buat manual dari phpMyAdmin dengan perintah <code>CREATE TABLE</code> di <code>includes/announcement.php</code>.
      </div>
    <?php else: ?>

      <section class="section">
        <div class="section-head"><span>Preview</span></div>
        <div class="section-body">
          <div class="preview">
            <div class="preview__caption">Tampilan di atas navbar — ubah pilihan animasi di bawah untuk melihatnya langsung</div>
            <?php if ($announcement !== null): ?>
              <?php include __DIR__ . '/../includes/announcement-bar.php'; ?>
              <div class="preview__nav"></div>
            <?php else: ?>
              <div class="preview__empty">Belum ada pengumuman aktif, jadi bar tidak tampil di storefront.</div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="section">
        <div class="section-head"><span>Animasi</span></div>
        <div class="section-body">
          <form action="dashboard-announcement.php" method="POST" id="settingsForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="settings-grid">
              <div class="form-group">
                <label for="animation">Jenis animasi</label>
                <select name="animation" id="animation" class="form-input">
                  <?php foreach (PPF_ANNOUNCEMENT_ANIMATIONS as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>"<?= $settings['animation'] === $code ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" id="intervalGroup">
                <label for="interval_seconds">Jeda antar pengumuman (detik)</label>
                <input type="number" name="interval_seconds" id="interval_seconds" class="form-input"
                       min="<?= PPF_ANNOUNCEMENT_MIN_INTERVAL ?>" max="<?= PPF_ANNOUNCEMENT_MAX_INTERVAL ?>" value="<?= (int) $settings['interval_seconds'] ?>">
                <p class="form-help"><?= PPF_ANNOUNCEMENT_MIN_INTERVAL ?>–<?= PPF_ANNOUNCEMENT_MAX_INTERVAL ?> detik.</p>
              </div>

              <div class="form-group" id="speedGroup">
                <label for="marquee_speed">Kecepatan berjalan</label>
                <input type="number" name="marquee_speed" id="marquee_speed" class="form-input"
                       min="<?= PPF_ANNOUNCEMENT_MIN_SPEED ?>" max="<?= PPF_ANNOUNCEMENT_MAX_SPEED ?>" value="<?= (int) $settings['marquee_speed'] ?>">
                <p class="form-help"><?= PPF_ANNOUNCEMENT_MIN_SPEED ?> (pelan) – <?= PPF_ANNOUNCEMENT_MAX_SPEED ?> (cepat). Hanya untuk mode berjalan.</p>
              </div>
            </div>

            <div class="hero-actions" style="margin-top:14px;">
              <button type="submit" class="btn">Simpan animasi</button>
            </div>
          </form>
        </div>
      </section>

      <section class="section">
        <div class="section-head"><span>Daftar pengumuman (<?= count($items) ?>/<?= PPF_ANNOUNCEMENT_MAX_ITEMS ?>)</span></div>
        <div class="section-body">
          <?php if (!$items): ?>
            <p class="subcopy" style="margin:0 0 16px;">Belum ada pengumuman. Tambahkan di bawah.</p>
          <?php endif; ?>

          <?php foreach ($items as $item): ?>
            <form action="dashboard-announcement.php" method="POST" class="msg-row<?= (int) $item['is_active'] === 1 ? '' : ' is-off-row' ?>">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">

              <div class="msg-row__field">
                <label for="m<?= (int) $item['id'] ?>">Teks</label>
                <input type="text" name="message" id="m<?= (int) $item['id'] ?>" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_MESSAGE ?>"
                       value="<?= htmlspecialchars($item['message']) ?>" required>
              </div>
              <div class="msg-row__field">
                <label>Label link</label>
                <input type="text" name="link_label" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_LABEL ?>" value="<?= htmlspecialchars((string) $item['link_label']) ?>" placeholder="More">
              </div>
              <div class="msg-row__field">
                <label>Link tujuan</label>
                <input type="text" name="link_url" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_URL ?>" value="<?= htmlspecialchars((string) $item['link_url']) ?>" placeholder="shipping.php">
              </div>
              <div class="msg-row__field">
                <label>Urutan</label>
                <input type="number" name="sort_order" class="form-input" min="0" max="<?= PPF_ANNOUNCEMENT_MAX_SORT ?>" value="<?= (int) $item['sort_order'] ?>">
              </div>
              <div class="msg-row__actions">
                <label class="msg-row__active"><input type="checkbox" name="is_active" value="1"<?= (int) $item['is_active'] === 1 ? ' checked' : '' ?>> <span>Aktif</span></label>
                <button type="submit" name="action" value="update" class="btn btn-sm">Simpan</button>
                <button type="submit" name="action" value="delete" class="btn-secondary btn-sm btn-danger" onclick="return confirm('Hapus pengumuman ini?');">Hapus</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="section">
        <div class="section-head"><span>Tambah pengumuman</span></div>
        <div class="section-body">
          <form action="dashboard-announcement.php" method="POST" class="msg-row" style="padding:0;border:0;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">

            <div class="msg-row__field">
              <label for="new_message">Teks <span class="counter" id="newCount"></span></label>
              <input type="text" name="message" id="new_message" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_MESSAGE ?>" placeholder="mis. New drop this Friday" required>
            </div>
            <div class="msg-row__field">
              <label>Label link</label>
              <input type="text" name="link_label" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_LABEL ?>" placeholder="More">
            </div>
            <div class="msg-row__field">
              <label>Link tujuan</label>
              <input type="text" name="link_url" class="form-input" maxlength="<?= PPF_ANNOUNCEMENT_MAX_URL ?>" placeholder="shop.php atau https://…">
            </div>
            <div class="msg-row__field">
              <label>Urutan</label>
              <input type="number" name="sort_order" class="form-input" min="0" max="<?= PPF_ANNOUNCEMENT_MAX_SORT ?>" value="<?= count($items) ?>">
            </div>
            <div class="msg-row__actions">
              <label class="msg-row__active"><input type="checkbox" name="is_active" value="1" checked> <span>Aktif</span></label>
              <button type="submit" class="btn btn-sm">Tambah</button>
            </div>
          </form>
        </div>
      </section>

    <?php endif; ?>
  </main>

  <script>
  (function () {
    'use strict';
    const bar = document.getElementById('announceBar');
    const animation = document.getElementById('animation');
    if (!animation) return;

    const interval = document.getElementById('interval_seconds');
    const speed = document.getElementById('marquee_speed');
    const intervalGroup = document.getElementById('intervalGroup');
    const speedGroup = document.getElementById('speedGroup');

    // Pilihan animasi langsung terlihat di preview (belum tersimpan sampai tombol Simpan ditekan).
    function applySettings() {
      const mode = animation.value;
      intervalGroup.style.opacity = mode === 'marquee' || mode === 'none' ? .45 : 1;
      speedGroup.style.opacity = mode === 'marquee' ? 1 : .45;
      if (!bar || !window.PPFAnnouncement) return;
      bar.dataset.mode = mode;
      bar.dataset.interval = interval.value;
      bar.dataset.speed = speed.value;
      window.PPFAnnouncement.mount(bar);
    }
    [animation, interval, speed].forEach(el => el.addEventListener('change', applySettings));
    applySettings();

    const newMessage = document.getElementById('new_message');
    const newCount = document.getElementById('newCount');
    if (newMessage && newCount) {
      const count = () => { newCount.textContent = newMessage.value.length + ' / ' + newMessage.maxLength; };
      newMessage.addEventListener('input', count);
      count();
    }
  })();
  </script>
</body>
</html>

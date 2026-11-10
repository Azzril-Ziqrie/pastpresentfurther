<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/page-content.php';

const PAGE_TEXT_SAVED_MESSAGES = [
    'saved' => 'Teks halaman disimpan.',
    'reset' => 'Teks dikembalikan ke bawaan.',
];

/* ── halaman yang sedang diedit ──────────────────────── */
$slug = (string) ($_REQUEST['page'] ?? array_key_first(PPF_PAGES));
if (!ppf_page_exists($slug)) {
    $slug = array_key_first(PPF_PAGES);
}
$page = PPF_PAGES[$slug];

/* ── preview: dokumen lengkap yang dimuat di <iframe> ─── */
/** Halaman contoh memakai CSS situs asli (termasuk dark mode), jadi hasilnya sama dengan storefront. */
function buildPagePreviewDocument(string $slug, array $values): string
{
    $mainClass = htmlspecialchars(PPF_PAGES[$slug]['main_class'], ENT_QUOTES, 'UTF-8');
    $assets    = '../assets/css/';
    $version   = static fn(string $file): int => (int) @filemtime(__DIR__ . '/../assets/css/' . $file);

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">'
        . '<link rel="stylesheet" href="' . $assets . 'site.css?v=' . $version('site.css') . '">'
        . '<link rel="stylesheet" href="' . $assets . 'static-pages.css?v=' . $version('static-pages.css') . '">'
        . '<style>body{animation:none!important;opacity:1!important;min-height:0}.legal-wrapper{padding-top:16px}</style>'
        . '</head><body><main class="' . $mainClass . '">' . ppf_page_render($slug, $values) . '</main></body></html>';
}

/** Nilai field dari form (hanya field yang dikenal halaman ini). */
function pageTextFormValues(array $page, array $post): array
{
    $values = [];
    foreach (array_keys($page['fields']) as $field) {
        $values[$field] = str_replace(["\r\n", "\r"], "\n", (string) ($post[$field] ?? ''));
    }
    return $values;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Sesi tidak valid.');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo buildPagePreviewDocument($slug, pageTextFormValues($page, $_POST));
    exit;
}

/* ── simpan / reset ──────────────────────────────────── */
$error  = '';
$values = null; // diisi bila simpan gagal, supaya ketikan admin tidak hilang

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        try {
            if ($action === 'save') {
                ppf_page_save($pdo, $slug, $_POST);
            } elseif ($action === 'reset') {
                ppf_page_reset($pdo, $slug);
            } else {
                throw new InvalidArgumentException('Aksi tidak dikenal.');
            }
            header('Location: dashboard-page-text.php?page=' . urlencode($slug) . '&done=' . ($action === 'save' ? 'saved' : 'reset'));
            exit;
        } catch (InvalidArgumentException $e) {
            $error  = $e->getMessage();
            $values = pageTextFormValues($page, $_POST);
        } catch (Throwable $e) {
            $error  = 'Gagal menyimpan: ' . $e->getMessage();
            $values = pageTextFormValues($page, $_POST);
        }
    }
}

/* ── data untuk tampilan ─────────────────────────────── */
// Tabel dibuat otomatis di sini bila belum ada (tanpa phpMyAdmin).
$saved        = ppf_page_saved($pdo, $slug);
$dbReady      = $saved !== null;
$isCustomized = !empty($saved);
$values       = $values ?? ppf_page_load($pdo, $slug);

$doneMessage = PAGE_TEXT_SAVED_MESSAGES[$_GET['done'] ?? ''] ?? '';
$csrf        = admin_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Page text — pastpresentfurther Admin</title>
  <style>
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
    .tabs a { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: 1px solid #d2d2d7; border-radius: 999px; background: #fff; color: #1d1d1f; font-size: 13px; font-weight: 500; text-decoration: none; transition: background .15s, border-color .15s; }
    .tabs a:hover { background: #f0f0f2; }
    .tabs a.is-active { background: #1d1d1f; border-color: #1d1d1f; color: #fff; }
    .tabs .dot { width: 6px; height: 6px; border-radius: 50%; background: #34c759; }

    .editor { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
    @media (max-width: 1100px) { .editor { grid-template-columns: 1fr; } }
    .editor textarea { width: 100%; font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; resize: vertical; }
    .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .counter { float: right; font-weight: 400; color: #86868b; }

    .preview-frame { width: 100%; height: 640px; border: 1px solid #e5e5ea; border-radius: 12px; background: #fff; }
    .help { margin: 0 0 16px; padding: 12px 14px; border-radius: 12px; background: #f5f5f7; font-size: 12px; line-height: 1.7; color: #515154; }
    .help code { padding: 1px 6px; border-radius: 6px; background: #fff; font-size: 12px; }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 4px; }
    .btn-danger { color: #9d1f1f !important; }
    .status-pill { margin-left: auto; font-size: 12px; color: #86868b; }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Page text'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <section class="hero">
      <div class="hero-left">
        <div class="eyebrow">pastpresentfurther Admin</div>
        <h1 class="headline">Page Text</h1>
        <p class="subcopy">
          Ubah teks halaman Shipping &amp; Returns, About, Contact, Stockist, dan Legal tanpa menyentuh kode.
          Perubahan langsung tampil di storefront setelah disimpan. Foto halaman About dan Contact diatur di menu <strong>Page photos</strong>.
        </p>
      </div>
    </section>

    <nav class="tabs" aria-label="Pilih halaman">
      <?php foreach (PPF_PAGES as $key => $info): ?>
        <?php $custom = $dbReady && $key === $slug ? $isCustomized : !empty(ppf_page_saved($pdo, $key)); ?>
        <a href="dashboard-page-text.php?page=<?= urlencode($key) ?>" class="<?= $key === $slug ? 'is-active' : '' ?>"<?= $key === $slug ? ' aria-current="page"' : '' ?>>
          <?= htmlspecialchars($info['label']) ?><?= $custom ? '<span class="dot" title="Sudah diedit"></span>' : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($doneMessage !== ''): ?><div class="alert success"><?= htmlspecialchars($doneMessage) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$dbReady): ?>
      <div class="alert error">
        Tabel <code>site_content</code> tidak bisa dibuat otomatis (user database kemungkinan tidak punya hak CREATE).
        Halaman tetap tampil dengan teks bawaan; buat tabelnya dari phpMyAdmin dengan perintah <code>CREATE TABLE</code> di <code>includes/page-content.php</code> agar bisa menyimpan.
      </div>
    <?php endif; ?>

    <div class="editor">
      <section class="section">
        <div class="section-head">
          <span><?= htmlspecialchars($page['label']) ?></span>
          <a href="../<?= htmlspecialchars($page['url']) ?>" target="_blank" rel="noopener" class="nav-link">Lihat halaman ↗</a>
        </div>
        <div class="section-body">
          <?php if (array_filter($page['fields'], static fn(array $f): bool => $f['type'] === 'textarea')): ?>
            <div class="help">
              <strong>Cara menulis:</strong>
              <code>## Judul</code> membuat judul bagian baru · baris kosong = paragraf baru ·
              <code>- butir</code> membuat daftar · <code>**tebal**</code> huruf tebal ·
              <code>[teks](shipping.php)</code> tautan (halaman situs, <code>mailto:</code>, atau <code>https://</code>).
            </div>
          <?php endif; ?>

          <form action="dashboard-page-text.php?page=<?= urlencode($slug) ?>" method="POST" id="textForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="page" value="<?= htmlspecialchars($slug) ?>">

            <?php foreach ($page['fields'] as $field => $spec): ?>
              <div class="field">
                <label for="f_<?= htmlspecialchars($field) ?>">
                  <?= htmlspecialchars($spec['label']) ?>
                  <?php if ($spec['type'] === 'textarea'): ?><span class="counter" data-counter-for="f_<?= htmlspecialchars($field) ?>"></span><?php endif; ?>
                </label>
                <?php if ($spec['type'] === 'textarea'): ?>
                  <textarea name="<?= htmlspecialchars($field) ?>" id="f_<?= htmlspecialchars($field) ?>" class="form-input"
                            rows="<?= (int) $spec['rows'] ?>" maxlength="<?= PPF_PAGE_MAX_LENGTH ?>"
                            <?= $spec['required'] ? 'required' : '' ?>><?= htmlspecialchars($values[$field] ?? '') ?></textarea>
                <?php else: ?>
                  <input type="<?= $spec['type'] === 'email' ? 'email' : 'text' ?>" name="<?= htmlspecialchars($field) ?>" id="f_<?= htmlspecialchars($field) ?>"
                         class="form-input" value="<?= htmlspecialchars($values[$field] ?? '') ?>" <?= $spec['required'] ? 'required' : '' ?>>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <div class="actions">
              <button type="submit" name="action" value="save" class="btn"<?= $dbReady ? '' : ' disabled' ?>>Simpan</button>
              <button type="submit" name="action" value="reset" class="btn-secondary btn-danger" formnovalidate
                      onclick="return confirm('Kembalikan teks halaman ini ke bawaan? Teks yang sudah Anda ubah akan hilang.');"<?= $isCustomized ? '' : ' disabled' ?>>Reset ke bawaan</button>
              <span class="status-pill"><?= $isCustomized ? 'Sudah diedit' : 'Memakai teks bawaan' ?></span>
            </div>
          </form>
        </div>
      </section>

      <section class="section">
        <div class="section-head"><span>Preview</span><span class="subcopy" style="margin:0;font-size:12px;">Diperbarui otomatis saat Anda mengetik</span></div>
        <div class="section-body">
          <iframe class="preview-frame" id="preview" title="Preview <?= htmlspecialchars($page['label']) ?>"></iframe>
        </div>
      </section>
    </div>
  </main>

  <script>
  (function () {
    'use strict';
    const PREVIEW_DELAY_MS = 350;
    const form = document.getElementById('textForm');
    const frame = document.getElementById('preview');
    let timer = null;

    /** Kirim isi form ke server; server merender dengan fungsi yang sama dengan halaman publik. */
    async function refreshPreview() {
      const data = new FormData(form);
      data.set('action', 'preview');
      try {
        const response = await fetch('dashboard-page-text.php?page=' + encodeURIComponent(form.elements.page.value), { method: 'POST', body: data });
        if (response.ok) frame.srcdoc = await response.text();
      } catch (e) { /* preview opsional: gagal tidak mengganggu pengeditan */ }
    }

    function schedulePreview() {
      clearTimeout(timer);
      timer = setTimeout(refreshPreview, PREVIEW_DELAY_MS);
    }

    function updateCounters() {
      form.querySelectorAll('[data-counter-for]').forEach(counter => {
        const input = document.getElementById(counter.dataset.counterFor);
        counter.textContent = input.value.length.toLocaleString('id-ID') + ' karakter';
      });
    }

    form.addEventListener('input', () => { updateCounters(); schedulePreview(); });
    updateCounters();
    refreshPreview();
  })();
  </script>
</body>
</html>

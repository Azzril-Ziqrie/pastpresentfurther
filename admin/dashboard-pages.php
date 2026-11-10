<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/site-images.php';
require_once __DIR__ . '/../includes/image-helper.php';

$pages = [
    'about'   => ['label' => 'About',   'file' => 'about.php',   'default' => 'assets/images/site/about-photo.jpg'],
    'contact' => ['label' => 'Contact', 'file' => 'contact.php', 'default' => 'assets/images/site/contact-photo.jpg'],
];
$uploadDir  = __DIR__ . '/../uploads/site/';
$uploadBase = 'uploads/site/';
$maxBytes   = 12 * 1024 * 1024;

$ready = ppf_site_images_ready($pdo);

function removeCustomPhoto(PDO $pdo, string $slug): void
{
    $st = $pdo->prepare('SELECT path FROM site_images WHERE slug = :s');
    $st->execute(['s' => $slug]);
    $old = (string) $st->fetchColumn();
    if ($old !== '' && strpos($old, 'uploads/site/') === 0 && is_file(__DIR__ . '/../' . $old)) {
        @unlink(__DIR__ . '/../' . $old);
    }
}

/* ── POST (CSRF-protected, post/redirect/get) ─────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = (string) ($_POST['page'] ?? '');
    try {
        if (!admin_csrf_ok($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired. Please try again.');
        if (!$ready || !isset($pages[$slug])) throw new RuntimeException('Unknown page.');

        if (($_POST['action'] ?? '') === 'reset') {
            removeCustomPhoto($pdo, $slug);
            $pdo->prepare('DELETE FROM site_images WHERE slug = :s')->execute(['s' => $slug]);
            $_SESSION['admin_flash'] = ['ok', $pages[$slug]['label'] . ' photo reset to the default.'];
        } else {
            $file = $_FILES['photo'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a photo first.');
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxBytes) throw new RuntimeException('Upload failed or file is larger than 12 MB.');
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Only JPG, PNG or WEBP photos are allowed.');
            if (!is_dir($uploadDir) || !is_writable($uploadDir)) throw new RuntimeException('Upload folder uploads/site/ is not writable.');

            $name = ppf_save_image_webp($file['tmp_name'], $mime, $uploadDir, $slug . '_');
            removeCustomPhoto($pdo, $slug);
            $pdo->prepare('INSERT INTO site_images (slug, path) VALUES (:s, :p) ON DUPLICATE KEY UPDATE path = VALUES(path)')
                ->execute(['s' => $slug, 'p' => $uploadBase . $name]);
            $_SESSION['admin_flash'] = ['ok', $pages[$slug]['label'] . ' photo updated.'];
        }
    } catch (Throwable $e) {
        $_SESSION['admin_flash'] = ['err', $e->getMessage()];
    }
    header('Location: dashboard-pages.php');
    exit;
}
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$csrf = admin_csrf_token();

function currentPhoto(PDO $pdo, string $slug, string $default): array
{
    $p = ppf_site_image($pdo, $slug, $default);
    return [$p, $p !== $default];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>pastpresentfurther — Page photos</title>
  <link rel="icon" type="image/png" href="../assets/images/brand/logo-admin.png">
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <style>
    .photos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .pcard{background:#fff;border:1px solid var(--adm-line);border-radius:var(--adm-r);overflow:hidden}
    .pcard__img{aspect-ratio:16/9;background:var(--adm-bg);overflow:hidden}
    .pcard__img img{width:100%;height:100%;object-fit:cover;display:block}
    .pcard__body{padding:16px 18px 18px}
    .pcard__head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px}
    .pcard__head h2{margin:0;font-size:15px;font-weight:650;letter-spacing:-.01em}
    .pcard__tag{font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;background:var(--adm-bg);color:var(--adm-mute)}
    .pcard__tag.is-custom{background:#e6f5ec;color:#14693a}
    .pcard p{margin:0 0 14px;font-size:12.5px;color:var(--adm-mute)}
    .pcard form{display:flex;flex-direction:column;gap:10px}
    .pcard .row{display:flex;gap:8px;flex-wrap:wrap}
    .flash{margin-bottom:16px;padding:10px 12px;border-radius:10px;font-size:13px}
    .flash.ok{background:#e6f5ec;color:#14693a}
    .flash.err{background:#fdeaea;color:#9d1f1f}
    .hint{margin:0 0 18px;font-size:13px;color:var(--adm-mute)}
    @media(max-width:900px){.photos{grid-template-columns:1fr}}
  </style>
</head>
<body class="adm">
<?php $adminTitle = 'Page photos'; include __DIR__ . '/../includes/admin-header.php'; ?>
  <main class="wrap">
    <section class="hero" style="grid-template-columns:1fr">
      <div>
        <div class="eyebrow">Storefront content</div>
        <h1 class="headline">Page photos</h1>
        <p class="subcopy">Change the photo shown on the About and Contact pages.</p>
      </div>
    </section>

    <?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" role="<?= $flash[0] === 'ok' ? 'status' : 'alert' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>
    <?php if (!$ready): ?><div class="flash err">Could not prepare the <code>site_images</code> table. Check database permissions.</div><?php endif; ?>
    <p class="hint">JPG, PNG or WEBP, up to 12 MB. The Contact page shows the photo in its full original ratio; About crops it to landscape (16:9). Photos are converted to WebP automatically.</p>

    <section class="photos">
      <?php foreach ($pages as $slug => $pg): [$src, $custom] = currentPhoto($pdo, $slug, $pg['default']); ?>
        <article class="pcard">
          <div class="pcard__img"><img src="../<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($pg['label']) ?> photo"></div>
          <div class="pcard__body">
            <div class="pcard__head">
              <h2><?= htmlspecialchars($pg['label']) ?></h2>
              <span class="pcard__tag<?= $custom ? ' is-custom' : '' ?>"><?= $custom ? 'Custom' : 'Default' ?></span>
            </div>
            <p>Shown on <a href="../<?= $pg['file'] ?>" target="_blank" rel="noopener"><?= $pg['file'] ?> ↗</a></p>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="page" value="<?= $slug ?>">
              <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
              <div class="row">
                <button type="submit" name="action" value="upload" class="btn">Upload photo</button>
                <?php if ($custom): ?><button type="submit" name="action" value="reset" class="btn-secondary" formnovalidate onclick="return confirm('Reset to the default photo?')">Reset to default</button><?php endif; ?>
              </div>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>

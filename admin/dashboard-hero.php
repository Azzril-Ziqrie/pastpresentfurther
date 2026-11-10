<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error   = '';

/* ── helpers ─────────────────────────────────────────── */
function tableExistsHero(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureHeroSlidesTable(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hero_slides (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) DEFAULT NULL,
                subtitle VARCHAR(255) DEFAULT NULL,
                button_text VARCHAR(80) DEFAULT NULL,
                button_link VARCHAR(500) DEFAULT NULL,
                image VARCHAR(500) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_active_sort (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function uploadHeroImage(array $file, string $uploadDir, string $uploadBasePath): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedMime[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF files are allowed.');
    }
    require_once __DIR__ . '/../includes/image-helper.php';
    $filename = ppf_save_image_webp($file['tmp_name'], $mime, $uploadDir, 'hero_'); // WebP when possible
    return $uploadBasePath . $filename;
}

function deleteImageFile(?string $relativePath): void
{
    if (!$relativePath) return;
    $abs = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (is_file($abs)) { @unlink($abs); }
}

/* ── upload directory setup ──────────────────────────── */
$uploadDir      = __DIR__ . '/../assets/images/hero/';
$uploadBasePath = 'assets/images/hero/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

/* ── ensure schema ───────────────────────────────────── */
$tableReady = tableExistsHero($pdo, 'hero_slides');
if (!$tableReady) {
    $tableReady = ensureHeroSlidesTable($pdo);
}

/* ── POST handlers ───────────────────────────────────── */
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create') {
        $title       = trim((string) ($_POST['title'] ?? ''));
        $subtitle    = trim((string) ($_POST['subtitle'] ?? ''));
        $buttonText  = trim((string) ($_POST['button_text'] ?? ''));
        $buttonLink  = trim((string) ($_POST['button_link'] ?? ''));
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($_FILES['image']['name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose an image file.';
        } else {
            try {
                $uploadedPath = uploadHeroImage($_FILES['image'], $uploadDir, $uploadBasePath);
                if ($uploadedPath === null) {
                    $error = 'Image upload failed.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO hero_slides (title, subtitle, button_text, button_link, image, sort_order, is_active)
                         VALUES (:title, :subtitle, :button_text, :button_link, :image, :sort_order, :is_active)'
                    );
                    $stmt->execute([
                        'title'       => $title !== '' ? $title : null,
                        'subtitle'    => $subtitle !== '' ? $subtitle : null,
                        'button_text' => $buttonText !== '' ? $buttonText : null,
                        'button_link' => $buttonLink !== '' ? $buttonLink : null,
                        'image'       => $uploadedPath,
                        'sort_order'  => $sortOrder,
                        'is_active'   => $isActive,
                    ]);
                    $message = 'Hero slide created successfully.';
                }
            } catch (Throwable $e) {
                $error = 'Upload failed: ' . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'update') {
        $slideId     = (int) ($_POST['slide_id'] ?? 0);
        $title       = trim((string) ($_POST['title'] ?? ''));
        $subtitle    = trim((string) ($_POST['subtitle'] ?? ''));
        $buttonText  = trim((string) ($_POST['button_text'] ?? ''));
        $buttonLink  = trim((string) ($_POST['button_link'] ?? ''));
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($slideId <= 0) {
            $error = 'Invalid slide id.';
        } else {
            try {
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT image FROM hero_slides WHERE id = :id FOR UPDATE');
                $find->execute(['id' => $slideId]);
                $row = $find->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $pdo->rollBack();
                    $error = 'Slide not found.';
                } else {
                    $newImagePath = $row['image'];
                    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $uploadedPath = uploadHeroImage($_FILES['image'], $uploadDir, $uploadBasePath);
                        if ($uploadedPath !== null) {
                            $newImagePath = $uploadedPath;
                        }
                    }
                    $upd = $pdo->prepare(
                        'UPDATE hero_slides
                            SET title = :title,
                                subtitle = :subtitle,
                                button_text = :button_text,
                                button_link = :button_link,
                                image = :image,
                                sort_order = :sort_order,
                                is_active = :is_active
                          WHERE id = :id'
                    );
                    $upd->execute([
                        'title'       => $title !== '' ? $title : null,
                        'subtitle'    => $subtitle !== '' ? $subtitle : null,
                        'button_text' => $buttonText !== '' ? $buttonText : null,
                        'button_link' => $buttonLink !== '' ? $buttonLink : null,
                        'image'       => $newImagePath,
                        'sort_order'  => $sortOrder,
                        'is_active'   => $isActive,
                        'id'          => $slideId,
                    ]);
                    $pdo->commit();

                    if ($newImagePath !== $row['image']) {
                        deleteImageFile($row['image']);
                    }
                    $message = 'Hero slide updated successfully.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Update failed: ' . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'delete') {
        $slideId = (int) ($_POST['slide_id'] ?? 0);
        if ($slideId <= 0) {
            $error = 'Invalid slide id.';
        } else {
            try {
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT image FROM hero_slides WHERE id = :id FOR UPDATE');
                $find->execute(['id' => $slideId]);
                $row = $find->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $pdo->prepare('DELETE FROM hero_slides WHERE id = :id')->execute(['id' => $slideId]);
                    $pdo->commit();
                    deleteImageFile($row['image']);
                    $message = 'Hero slide deleted.';
                } else {
                    $pdo->rollBack();
                    $error = 'Slide not found.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'toggle') {
        $slideId = (int) ($_POST['slide_id'] ?? 0);
        if ($slideId > 0) {
            $pdo->prepare('UPDATE hero_slides SET is_active = 1 - is_active WHERE id = :id')
                ->execute(['id' => $slideId]);
            $message = 'Slide status updated.';
        }
    }
}

/* ── load slides ─────────────────────────────────────── */
$slides = [];
if ($tableReady) {
    $slides = $pdo->query(
        'SELECT id, title, subtitle, button_text, button_link, image, sort_order, is_active, created_at, updated_at
           FROM hero_slides
          ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/* ── load for edit ───────────────────────────────────── */
$editing = null;
$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
if ($tableReady && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM hero_slides WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hero Slides Manager — Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; background: #ffffff; color: #111; }
    a { color: inherit; text-decoration: none; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #ffffff;
      border-bottom: 1px solid #d8d5ce;
      position: sticky;
      top: 0;
      z-index: 20;
    }
    .topbar__left, .topbar__right {
      display: flex;
      align-items: center;
      gap: 18px;
    }
    .topbar__right { justify-content: flex-end; }
    .logo { width: 140px; justify-self: center; display: flex; align-items: center; justify-content: center; }
    .logo img { width: 100%; height: 36px; object-fit: contain; }
    .nav-link { font-size: 12px; text-transform: uppercase; letter-spacing: .6px; }

    .wrap { max-width: 1440px; margin: 0 auto; border-left: 1px solid #d8d5ce; border-right: 1px solid #d8d5ce; min-height: calc(100vh - 74px); }

    .hero { padding: 22px 20px; border-bottom: 1px solid #d8d5ce; display: grid; grid-template-columns: 1.2fr .8fr; gap: 0; }
    .hero-left, .hero-right { min-width: 0; }
    .hero-right { border-left: 1px solid #d8d5ce; padding-left: 20px; }
    .eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 14px; }
    .headline { margin: 0; font-size: 36px; line-height: 1.08; text-transform: uppercase; font-weight: 400; max-width: 620px; }
    .subcopy { margin: 18px 0 0; max-width: 560px; font-size: 14px; line-height: 1.7; color: #555; }
    .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 24px; }
    .btn, .btn-secondary {
      height: 48px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }
    .btn-secondary { background: #faf9f5; color: #111; border-color: #d8d5ce; }
    .btn-secondary:hover { background: #f4f2ec; }
    .btn-sm { height: 36px; padding: 0 14px; font-size: 10px; }
    .btn-danger { background: #fff; color: #c62828; border-color: #c62828; }
    .btn-danger:hover { background: #c62828; color: #fff; }

    .section { min-width: 0; border-bottom: 1px solid #d8d5ce; }
    .section-head {
      padding: 18px 20px;
      border-bottom: 1px solid #d8d5ce;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: #faf9f5;
    }
    .section-body { padding: 20px; background: #ffffff; }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-group { margin-bottom: 14px; }
    .form-group.full { grid-column: 1 / -1; }
    .form-group label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 6px; color: #555; }
    .form-input, .form-textarea, .form-file {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d8d5ce;
      background: #fff;
      font-size: 13px;
      font-family: inherit;
    }
    .form-textarea { resize: vertical; min-height: 60px; }
    .form-row-check { display: flex; align-items: center; gap: 8px; }
    .form-row-check input { width: 16px; height: 16px; }
    .form-help { font-size: 11px; color: #888; margin-top: 4px; text-transform: none; }

    .alert { padding: 12px 14px; font-size: 12px; text-transform: none; letter-spacing: .02em; margin: 16px 20px 0; border: 1px solid #d8d5ce; }
    .alert.success { background: #f0f7f0; color: #2e7d32; border-color: #2e7d32; }
    .alert.error   { background: #fdf0f0; color: #c62828; border-color: #c62828; }

    .table-wrap { overflow: auto; border: 1px solid #d8d5ce; background: #faf9f5; }
    table { width: 100%; border-collapse: collapse; min-width: 720px; }
    th, td {
      border-bottom: 1px solid #e8e6df;
      padding: 14px 12px;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th { text-transform: uppercase; letter-spacing: .6px; font-size: 12px; background: #f4f2ec; }
    .thumb-mini { width: 100px; height: 56px; border: 1px solid #e0ddd5; background: #fff; object-fit: cover; }

    .status { display: inline-block; padding: 4px 8px; border: 1px solid #d8d5ce; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; background: #faf9f5; }
    .status.live { border-color: #111; background: #f4f2ec; }
    .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .row-actions form { display: inline; }

    .preview-img { max-width: 240px; max-height: 120px; object-fit: cover; border: 1px solid #d8d5ce; margin-top: 8px; }

    @media (max-width: 980px) {
      .hero { grid-template-columns: 1fr; }
      .hero-right { border-left: 0; padding-left: 0; margin-top: 20px; }
      .form-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 0 16px; }
      .headline { font-size: 28px; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <?php $adminTitle = 'Hero slides'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <section class="hero">
      <div class="hero-left">
        <div class="eyebrow">pastpresentfurther Admin</div>
        <h1 class="headline">Hero Slides Manager</h1>
        <p class="subcopy">
          Add, edit, and remove the photos that appear in the homepage hero carousel.
          Each slide can have its own title, subtitle, button text, and button link.
          Slides are ordered by <strong>sort order</strong> and only active slides are shown on the storefront.
        </p>
        <div class="hero-actions">
          <a href="#form-section" class="btn"><?= $editing ? 'Edit Slide' : 'Add New Slide' ?></a>
          <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
          <a href="../index.php" class="btn-secondary">View Storefront</a>
        </div>
      </div>
      <div class="hero-right">
        <div class="eyebrow">Statistics</div>
        <p class="subcopy" style="margin-top:0;">
          <strong>Total slides:</strong> <?= count($slides) ?><br>
          <strong>Active:</strong> <?= count(array_filter($slides, static fn($s) => (int) $s['is_active'] === 1)) ?><br>
          <strong>Hidden:</strong> <?= count(array_filter($slides, static fn($s) => (int) $s['is_active'] === 0)) ?>
        </p>
      </div>
    </section>

    <?php if ($message): ?>
      <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
      <div class="alert error">
        Table <code>hero_slides</code> is not available and could not be created automatically.
        Please import <code>config/hero_slides.sql</code> manually.
      </div>
    <?php else: ?>

      <section class="section" id="form-section">
        <div class="section-head">
          <span><?= $editing ? 'Edit Slide #' . (int) $editing['id'] : 'Add New Slide' ?></span>
          <?php if ($editing): ?>
            <a href="dashboard-hero.php" class="nav-link">Cancel edit</a>
          <?php endif; ?>
        </div>
        <div class="section-body">
          <form action="dashboard-hero.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?>
              <input type="hidden" name="slide_id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
              <div class="form-group full">
                <label for="image">Image <?= $editing ? '(leave empty to keep current)' : '' ?></label>
                <input type="file" name="image" id="image" class="form-file" accept="image/*" <?= $editing ? '' : 'required' ?>>
                <?php if ($editing && !empty($editing['image'])): ?>
                  <img class="preview-img" src="../<?= htmlspecialchars($editing['image']) ?>" alt="Current image">
                <?php endif; ?>
                <p class="form-help">Recommended size: 1600×900px or larger. JPG, PNG, WEBP, GIF.</p>
              </div>

              <div class="form-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title" class="form-input" value="<?= htmlspecialchars((string)($editing['title'] ?? '')) ?>" placeholder="Optional title">
              </div>

              <div class="form-group">
                <label for="subtitle">Subtitle</label>
                <input type="text" name="subtitle" id="subtitle" class="form-input" value="<?= htmlspecialchars((string)($editing['subtitle'] ?? '')) ?>" placeholder="Optional subtitle">
              </div>

              <div class="form-group">
                <label for="button_text">Button Text</label>
                <input type="text" name="button_text" id="button_text" class="form-input" value="<?= htmlspecialchars((string)($editing['button_text'] ?? '')) ?>" placeholder="e.g. Shop Now">
              </div>

              <div class="form-group">
                <label for="button_link">Button Link</label>
                <input type="text" name="button_link" id="button_link" class="form-input" value="<?= htmlspecialchars((string)($editing['button_link'] ?? '')) ?>" placeholder="e.g. shop.php or https://...">
              </div>

              <div class="form-group">
                <label for="sort_order">Sort Order</label>
                <input type="number" name="sort_order" id="sort_order" class="form-input" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                <p class="form-help">Lower numbers show first.</p>
              </div>

              <div class="form-group">
                <label>Status</label>
                <label class="form-row-check">
                  <input type="checkbox" name="is_active" value="1" <?= $editing ? ((int) $editing['is_active'] === 1 ? 'checked' : '') : 'checked' ?>>
                  <span>Active (show on storefront)</span>
                </label>
              </div>
            </div>

            <div class="hero-actions" style="margin-top:14px;">
              <button type="submit" class="btn"><?= $editing ? 'Save Changes' : 'Add Slide' ?></button>
              <?php if ($editing): ?>
                <a href="dashboard-hero.php" class="btn btn-secondary">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </section>

      <section class="section">
        <div class="section-head">
          <span>Existing Slides</span>
          <span style="font-size:11px;color:#888;"><?= count($slides) ?> slide(s)</span>
        </div>
        <div class="section-body">
          <?php if (!$slides): ?>
            <p style="font-size:13px;color:#888;margin:0;">No hero slides yet. Add one using the form above.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Image</th>
                    <th>Title / Subtitle</th>
                    <th>Button</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($slides as $s): ?>
                    <tr>
                      <td>
                        <img class="thumb-mini" src="../<?= htmlspecialchars($s['image']) ?>" alt="<?= htmlspecialchars((string) $s['title']) ?>">
                      </td>
                      <td>
                        <strong><?= htmlspecialchars((string) $s['title']) ?></strong>
                        <?php if (!empty($s['subtitle'])): ?>
                          <br><span style="color:#888;font-size:12px;"><?= htmlspecialchars((string) $s['subtitle']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($s['button_text'])): ?>
                          <?= htmlspecialchars((string) $s['button_text']) ?>
                          <?php if (!empty($s['button_link'])): ?>
                            <br><a href="<?= htmlspecialchars((string) $s['button_link']) ?>" target="_blank" style="font-size:11px;color:#888;"><?= htmlspecialchars((string) $s['button_link']) ?></a>
                          <?php endif; ?>
                        <?php else: ?>
                          <span style="color:#bbb;">—</span>
                        <?php endif; ?>
                      </td>
                      <td><?= (int) $s['sort_order'] ?></td>
                      <td>
                        <span class="status <?= (int) $s['is_active'] === 1 ? 'live' : '' ?>">
                          <?= (int) $s['is_active'] === 1 ? 'Active' : 'Hidden' ?>
                        </span>
                      </td>
                      <td>
                        <div class="row-actions">
                          <a href="dashboard-hero.php?edit=<?= (int) $s['id'] ?>#form-section" class="btn btn-sm btn-secondary">Edit</a>
                          <form method="POST">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="slide_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Toggle</button>
                          </form>
                          <form method="POST" onsubmit="return confirm('Delete this slide?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slide_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

    <?php endif; ?>

  </main>
</body>
</html>

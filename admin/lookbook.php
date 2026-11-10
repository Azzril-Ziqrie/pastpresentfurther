<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error   = '';

/* ── helpers ─────────────────────────────────────────── */
function tableExistsAdmin(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureYearTitlesTable(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS lookbook_year_titles (
                year INT NOT NULL PRIMARY KEY,
                title VARCHAR(255) DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function saveYearTitle(PDO $pdo, int $year, string $title): void
{
    $title = trim($title);
    $stmt = $pdo->prepare(
        'INSERT INTO lookbook_year_titles (year, title) VALUES (:year, :title)
         ON DUPLICATE KEY UPDATE title = VALUES(title)'
    );
    $stmt->execute([
        'year'  => $year,
        'title' => $title !== '' ? $title : null,
    ]);
}

/* ── upload directory setup ──────────────────────────── */
$uploadDir      = __DIR__ . '/../assets/images/lookbook/';
$uploadBasePath = 'assets/images/lookbook/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

/* ── upload helper ───────────────────────────────────── */
function uploadLookbookImage(array $file, string $uploadDir, string $uploadBasePath): ?string
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
    $filename = ppf_save_image_webp($file['tmp_name'], $mime, $uploadDir, 'lookbook_'); // WebP when possible
    return $uploadBasePath . $filename;
}

/* ── schema check ────────────────────────────────────── */
$tableReady      = tableExistsAdmin($pdo, 'lookbook_photos');
$yearTitlesReady = $tableReady && ensureYearTitlesTable($pdo);

/* ── POST handlers ───────────────────────────────────── */
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'upload') {
        $year      = (int) ($_POST['year'] ?? 0);
        $yearTitle = trim((string) ($_POST['year_title'] ?? ''));

        if ($year < 2000 || $year > 2100) {
            $error = 'Please enter a valid year between 2000 and 2100.';
        } elseif (empty($_FILES['photos']['name']) || (is_array($_FILES['photos']['name']) && count(array_filter($_FILES['photos']['name'])) === 0)) {
            $error = 'Please select at least one photo to upload.';
        } else {
            try {
                $pdo->beginTransaction();

                $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM lookbook_photos');
                $sortStmt->execute();
                $startSort = (int) $sortStmt->fetchColumn();
                $nextSort  = $startSort + 1;

                $totalFiles = is_array($_FILES['photos']['name']) ? count($_FILES['photos']['name']) : 1;
                $uploaded   = 0;
                $skipped    = 0;

                for ($i = 0; $i < $totalFiles; $i++) {
                    $singleFile = [
                        'name'     => is_array($_FILES['photos']['name'])     ? $_FILES['photos']['name'][$i]     : $_FILES['photos']['name'],
                        'type'     => is_array($_FILES['photos']['type'])     ? $_FILES['photos']['type'][$i]     : $_FILES['photos']['type'],
                        'tmp_name' => is_array($_FILES['photos']['tmp_name']) ? $_FILES['photos']['tmp_name'][$i] : $_FILES['photos']['tmp_name'],
                        'error'    => is_array($_FILES['photos']['error'])    ? $_FILES['photos']['error'][$i]    : $_FILES['photos']['error'],
                        'size'     => is_array($_FILES['photos']['size'])     ? $_FILES['photos']['size'][$i]     : $_FILES['photos']['size'],
                    ];
                    try {
                        $uploadedPath = uploadLookbookImage($singleFile, $uploadDir, $uploadBasePath);
                        if ($uploadedPath === null) {
                            $skipped++;
                            continue;
                        }
                        $insert = $pdo->prepare(
                            'INSERT INTO lookbook_photos (year, photo_path, caption, sort_order, is_active)
                             VALUES (:year, :photo_path, NULL, :sort_order, 1)'
                        );
                        $insert->execute([
                            'year'       => $year,
                            'photo_path' => $uploadedPath,
                            'sort_order' => $nextSort++,
                        ]);
                        $uploaded++;
                    } catch (RuntimeException $fileErr) {
                        $skipped++;
                    }
                }

                // Year title applies to the whole year, not to individual photos.
                if ($yearTitlesReady) {
                    saveYearTitle($pdo, $year, $yearTitle);
                }

                $pdo->commit();
                $message = $uploaded > 0 ? "{$uploaded} photo(s) uploaded to year {$year}." : 'Upload failed.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Upload failed: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'save_year_title') {
        $year      = (int) ($_POST['year'] ?? 0);
        $yearTitle = trim((string) ($_POST['year_title'] ?? ''));
        if ($year < 2000 || $year > 2100) {
            $error = 'Please enter a valid year between 2000 and 2100.';
        } elseif ($yearTitlesReady) {
            saveYearTitle($pdo, $year, $yearTitle);
            $message = "Year title for {$year} saved.";
        }
    } elseif ($_POST['action'] === 'delete_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        if ($photoId > 0) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT photo_path FROM lookbook_photos WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $photoId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $pdo->prepare('DELETE FROM lookbook_photos WHERE id = :id')->execute(['id' => $photoId]);
                    $pdo->commit();
                    $abs = __DIR__ . '/../' . $row['photo_path'];
                    if (is_file($abs)) { @unlink($abs); }
                    $message = 'Photo deleted successfully.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'toggle_active') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        if ($photoId > 0) {
            $pdo->prepare('UPDATE lookbook_photos SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $photoId]);
            $message = 'Photo status updated.';
        }
    }
}

$filterYear = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$photos     = [];
$yearSummary = [];
$yearTitles  = [];
if ($tableReady) {
    $sql = 'SELECT id, year, photo_path, sort_order, is_active, created_at FROM lookbook_photos';
    if ($filterYear > 0) {
        $stmt = $pdo->prepare($sql . ' WHERE year = :year ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['year' => $filterYear]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $photos = $pdo->query($sql . ' ORDER BY year DESC, sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
    $yearSummary = $pdo->query('SELECT year, COUNT(*) AS cnt FROM lookbook_photos GROUP BY year ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);

    if ($yearTitlesReady) {
        $rows = $pdo->query('SELECT year, title FROM lookbook_year_titles')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $yearTitles[(int) $r['year']] = $r['title'];
        }
    }
}
$currentYear = (int) date('Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lookbook Manager — Admin</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: #fff; color: #111; margin: 0; padding: 0; }
    .topbar { height: 74px; display: flex; align-items: center; justify-content: space-between; padding: 0 22px; border-bottom: 1px solid #d8d5ce; }
    .logo img { height: 36px; }
    .wrap { max-width: 1440px; margin: 0 auto; border: 1px solid #d8d5ce; }
    .hero { padding: 22px 20px; border-bottom: 1px solid #d8d5ce; }
    .btn { height: 44px; background: #111; color: #fff; padding: 0 18px; border: none; cursor: pointer; text-transform: uppercase; font-size: 11px; }
    .btn-sm { height: 34px; padding: 0 14px; font-size: 10px; }
    .section-grid { display: grid; grid-template-columns: 1fr 2fr; }
    .section { border-right: 1px solid #d8d5ce; border-bottom: 1px solid #d8d5ce; }
    .section-head { padding: 18px 20px; background: #faf9f5; text-transform: uppercase; font-size: 12px; border-bottom: 1px solid #d8d5ce; }
    .section-body { padding: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group small { display: block; margin-top: 4px; color: #888; font-size: 11px; text-transform: none; }
    .form-input { width: 100%; padding: 10px; border: 1px solid #d8d5ce; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 13px; }
    .thumb-mini { width: 50px; height: 60px; object-fit: cover; }
    .alert { padding: 10px; background: #eee; margin-bottom: 10px; font-size: 12px; }
    .year-title-row { display: flex; gap: 8px; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
    .year-title-row .yr { width: 60px; font-weight: bold; font-size: 13px; }
    .year-title-row input { flex: 1; padding: 8px; border: 1px solid #d8d5ce; font-size: 12px; }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Lookbook'; include __DIR__ . '/../includes/admin-header.php'; ?>
  <main class="wrap">
    <section class="hero">
      <h1>Lookbook Manager</h1>
      <p>Kelola foto lookbook tahunan. Judul di bawah ini berlaku untuk satu tahun (bukan per foto), dan akan tampil di bawah tahun pada halaman lookbook.</p>
    </section>
    <?php if ($tableReady): ?>
      <?php if ($message): ?><div class="alert" style="margin:16px 20px 0;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert" style="margin:16px 20px 0;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <section class="section-grid">
        <div class="section">
          <div class="section-head">Upload Photos</div>
          <div class="section-body">
            <form action="" method="POST" enctype="multipart/form-data">
              <input type="hidden" name="action" value="upload">
              <div class="form-group">
                <label>Year</label>
                <input type="number" name="year" class="form-input" value="<?= $currentYear ?>" required>
              </div>
              <div class="form-group">
                <label>Judul Tahun (Year Title)</label>
                <input type="text" name="year_title" class="form-input" placeholder="mis. Summer Collection 2026">
                <small>Judul ini berlaku untuk seluruh tahun yang dipilih, akan tampil di bawah tahun di halaman lookbook. Kosongkan jika tidak ingin mengubah judul yang sudah ada.</small>
              </div>
              <div class="form-group">
                <label>Photos</label>
                <input type="file" name="photos[]" multiple required>
              </div>
              <button type="submit" class="btn">Upload</button>
            </form>
          </div>
        </div>

        <div class="section">
          <div class="section-head">Photo List</div>
          <div class="section-body">
            <table>
              <thead>
                <tr>
                  <th>Thumb</th>
                  <th>Year</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($photos as $photo): ?>
                <tr>
                  <td><img class="thumb-mini" src="../<?= htmlspecialchars($photo['photo_path']) ?>"></td>
                  <td><?= (int) $photo['year'] ?></td>
                  <td><?= $photo['is_active'] ? 'Active' : 'Hidden' ?></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                      <button type="submit">Toggle</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                      <input type="hidden" name="action" value="delete_photo">
                      <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                      <button type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <?php if ($yearTitlesReady && $yearSummary): ?>
      <section class="section" style="border-right:none;">
        <div class="section-head">Judul Tahun (Year Titles)</div>
        <div class="section-body">
          <?php foreach ($yearSummary as $ys): $yr = (int) $ys['year']; ?>
            <form method="POST" class="year-title-row">
              <input type="hidden" name="action" value="save_year_title">
              <input type="hidden" name="year" value="<?= $yr ?>">
              <span class="yr"><?= $yr ?></span>
              <input type="text" name="year_title" value="<?= htmlspecialchars($yearTitles[$yr] ?? '') ?>" placeholder="Judul untuk tahun <?= $yr ?>">
              <button type="submit" class="btn btn-sm">Save</button>
            </form>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
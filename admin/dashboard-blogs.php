<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error   = '';

/* ── helpers ─────────────────────────────────────────── */
function blogTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function blogUploadDir(): string
{
    return __DIR__ . '/../uploads/blogs/';
}

function blogUploadBasePath(): string
{
    return 'uploads/blogs/';
}

if (!is_dir(blogUploadDir())) {
    @mkdir(blogUploadDir(), 0777, true);
}

function blogUploadImage(array $file, string $fieldName): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(ucfirst($fieldName) . ' upload failed (code ' . $file['error'] . ').');
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
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF files are allowed for ' . $fieldName . '.');
    }
    $extension  = $allowedMime[$mime];
    $filename   = uniqid('blog_', true) . '.' . $extension;
    $targetPath = blogUploadDir() . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to move uploaded ' . $fieldName . '.');
    }
    return blogUploadBasePath() . $filename;
}

function blogUnlinkImage(?string $path): void
{
    if (!$path) return;
    $abs = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($abs)) { @unlink($abs); }
}

/* ── schema check ────────────────────────────────────── */
$blogsTableReady    = blogTableExists($pdo, 'blogs');
$galleryTableReady  = blogTableExists($pdo, 'blog_gallery');

/* ── POST handlers ───────────────────────────────────── */
if ($blogsTableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create' || $_POST['action'] === 'update') {
        $title     = trim((string) ($_POST['title'] ?? ''));
        $subtitle  = trim((string) ($_POST['subtitle'] ?? ''));
        $author    = trim((string) ($_POST['author'] ?? ''));
        $content   = trim((string) ($_POST['content'] ?? ''));
        $extraText = trim((string) ($_POST['extra_text'] ?? ''));

        if ($title === '' || $content === '') {
            $error = 'Title and content are required.';
        } else {
            try {
                $mainImagePath = null;
                if (!empty($_FILES['main_image']['name'])) {
                    $mainImagePath = blogUploadImage($_FILES['main_image'], 'main image');
                }

                if ($_POST['action'] === 'create') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO blogs (title, subtitle, author, content, extra_text, main_image, created_at)
                         VALUES (:title, :subtitle, :author, :content, :extra_text, :main_image, NOW())'
                    );
                    $stmt->execute([
                        'title'      => $title,
                        'subtitle'   => $subtitle !== '' ? $subtitle : null,
                        'author'     => $author !== '' ? $author : null,
                        'content'    => $content,
                        'extra_text' => $extraText !== '' ? $extraText : null,
                        'main_image' => $mainImagePath,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    if ($galleryTableReady && !empty($_FILES['gallery']['name'][0])) {
                        $totalFiles = count($_FILES['gallery']['name']);
                        for ($i = 0; $i < $totalFiles; $i++) {
                            $single = [
                                'name'     => $_FILES['gallery']['name'][$i],
                                'type'     => $_FILES['gallery']['type'][$i],
                                'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                                'error'    => $_FILES['gallery']['error'][$i],
                                'size'     => $_FILES['gallery']['size'][$i],
                            ];
                            try {
                                $path = blogUploadImage($single, 'gallery image');
                                if ($path) {
                                    $g = $pdo->prepare(
                                        'INSERT INTO blog_gallery (blog_id, image_url) VALUES (:blog_id, :image_url)'
                                    );
                                    $g->execute(['blog_id' => $newId, 'image_url' => $path]);
                                }
                            } catch (RuntimeException $ge) {
                                // skip individual gallery failures
                            }
                        }
                    }
                    $message = 'Blog article created successfully.';
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        $error = 'Invalid article ID.';
                    } else {
                        $existing = $pdo->prepare('SELECT * FROM blogs WHERE id = :id');
                        $existing->execute(['id' => $id]);
                        $row = $existing->fetch(PDO::FETCH_ASSOC);
                        if (!$row) {
                            $error = 'Article not found.';
                        } else {
                            $finalMain = $row['main_image'];
                            if ($mainImagePath) {
                                blogUnlinkImage($row['main_image']);
                                $finalMain = $mainImagePath;
                            }

                            $upd = $pdo->prepare(
                                'UPDATE blogs
                                 SET title = :title, subtitle = :subtitle, author = :author,
                                     content = :content, extra_text = :extra_text, main_image = :main_image
                                 WHERE id = :id'
                            );
                            $upd->execute([
                                'title'      => $title,
                                'subtitle'   => $subtitle !== '' ? $subtitle : null,
                                'author'     => $author !== '' ? $author : null,
                                'content'    => $content,
                                'extra_text' => $extraText !== '' ? $extraText : null,
                                'main_image' => $finalMain,
                                'id'         => $id,
                            ]);

                            if ($galleryTableReady && !empty($_FILES['gallery']['name'][0])) {
                                $totalFiles = count($_FILES['gallery']['name']);
                                for ($i = 0; $i < $totalFiles; $i++) {
                                    $single = [
                                        'name'     => $_FILES['gallery']['name'][$i],
                                        'type'     => $_FILES['gallery']['type'][$i],
                                        'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                                        'error'    => $_FILES['gallery']['error'][$i],
                                        'size'     => $_FILES['gallery']['size'][$i],
                                    ];
                                    try {
                                        $path = blogUploadImage($single, 'gallery image');
                                        if ($path) {
                                            $g = $pdo->prepare(
                                                'INSERT INTO blog_gallery (blog_id, image_url) VALUES (:blog_id, :image_url)'
                                            );
                                            $g->execute(['blog_id' => $id, 'image_url' => $path]);
                                        }
                                    } catch (RuntimeException $ge) {
                                        // skip
                                    }
                                }
                            }
                            $message = 'Blog article updated successfully.';
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = 'Save failed: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT main_image FROM blogs WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($galleryTableReady) {
                    $gals = $pdo->prepare('SELECT image_url FROM blog_gallery WHERE blog_id = :id');
                    $gals->execute(['id' => $id]);
                    foreach ($gals->fetchAll(PDO::FETCH_ASSOC) as $g) {
                        blogUnlinkImage($g['image_url']);
                    }
                    $pdo->prepare('DELETE FROM blog_gallery WHERE blog_id = :id')->execute(['id' => $id]);
                }
                $pdo->prepare('DELETE FROM blogs WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();
                if ($row) { blogUnlinkImage($row['main_image']); }
                $message = 'Article deleted successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'toggle_active') {
        $message = 'Publish/draft toggle is not available (is_active column missing).';
    } elseif ($_POST['action'] === 'delete_gallery') {
        if (!$galleryTableReady) {
            $error = 'Gallery table not available.';
        } else {
            $gid = (int) ($_POST['gallery_id'] ?? 0);
            if ($gid > 0) {
                $stmt = $pdo->prepare('SELECT image_url FROM blog_gallery WHERE id = :id');
                $stmt->execute(['id' => $gid]);
                $g = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($g) {
                    $pdo->prepare('DELETE FROM blog_gallery WHERE id = :id')->execute(['id' => $gid]);
                    blogUnlinkImage($g['image_url']);
                    $message = 'Gallery image deleted.';
                }
            }
        }
    }
}

/* ── data loading ────────────────────────────────────── */
$editBlog = null;
$editGallery = [];
if ($blogsTableReady) {
    if (isset($_GET['edit'])) {
        $eid = (int) $_GET['edit'];
        $st = $pdo->prepare('SELECT * FROM blogs WHERE id = :id');
        $st->execute(['id' => $eid]);
        $editBlog = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($editBlog && $galleryTableReady) {
            $g = $pdo->prepare('SELECT * FROM blog_gallery WHERE blog_id = :id ORDER BY id ASC');
            $g->execute(['id' => $eid]);
            $editGallery = $g->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $blogs = $pdo->query('SELECT * FROM blogs ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Research / Blog Manager — Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; background: #fff; color: #111; }
    a { color: inherit; text-decoration: none; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #fff;
      border-bottom: 1px solid #d8d5ce;
    }
    .topbar__left, .topbar__right { display: flex; align-items: center; gap: 18px; }
    .topbar__right { justify-content: flex-end; }
    .logo { width: 140px; justify-self: center; display: flex; align-items: center; justify-content: center; }
    .logo img { width: 100%; height: 36px; object-fit: contain; }
    .nav-link { font-size: 12px; text-transform: uppercase; letter-spacing: .6px; }

    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      border-left: 1px solid #d8d5ce;
      border-right: 1px solid #d8d5ce;
      min-height: calc(100vh - 74px);
    }

    .hero {
      padding: 22px 20px;
      border-bottom: 1px solid #d8d5ce;
    }
    .eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 14px; }
    .headline { margin: 0; font-size: 32px; line-height: 1.1; text-transform: uppercase; font-weight: 400; }
    .subcopy { margin: 14px 0 0; max-width: 720px; font-size: 13px; line-height: 1.7; color: #555; }
    .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

    .btn, .btn-secondary {
      height: 44px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }
    .btn-secondary { background: #faf9f5; color: #111; border-color: #d8d5ce; }
    .btn-secondary:hover { background: #f4f2ec; }
    .btn-sm { height: 32px; padding: 0 12px; font-size: 10px; }

    .alert { padding: 12px 14px; border: 1px solid #d8d5ce; background: #faf9f5; margin: 16px 20px 0; font-size: 13px; }
    .alert.success { border-color: #111; background: #f4f2ec; }
    .alert.error   { border-color: #b66; background: #fbeeee; color: #800; }

    .section-grid { display: grid; grid-template-columns: 1fr 1.6fr; }
    .section { border-right: 1px solid #d8d5ce; border-bottom: 1px solid #d8d5ce; }
    .section:nth-child(2n) { border-right: 0; }
    .section-head { padding: 16px 20px; background: #faf9f5; text-transform: uppercase; font-size: 12px; letter-spacing: .7px; border-bottom: 1px solid #d8d5ce; }
    .section-body { padding: 20px; }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 6px; color: #555; }
    .form-input, .form-textarea, .form-file {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d8d5ce;
      background: #fff;
      font-size: 13px;
      font-family: inherit;
    }
    .form-textarea { min-height: 220px; resize: vertical; line-height: 1.5; }
    .form-file { padding: 6px 10px; }
    .form-row { display: flex; gap: 14px; }
    .form-row > .form-group { flex: 1; }
    .form-help { font-size: 11px; color: #888; margin-top: 4px; }
    .form-check { display: flex; align-items: center; gap: 8px; font-size: 12px; }
    .form-check input { width: 16px; height: 16px; }

    .table-wrap { overflow: auto; border: 1px solid #d8d5ce; background: #faf9f5; }
    table { width: 100%; border-collapse: collapse; min-width: 760px; }
    th, td { border-bottom: 1px solid #e8e6df; padding: 12px; text-align: left; font-size: 13px; vertical-align: top; }
    th { text-transform: uppercase; letter-spacing: .6px; font-size: 11px; background: #f4f2ec; }
    .thumb-mini { width: 60px; height: 40px; object-fit: cover; border: 1px solid #e0ddd5; background: #fff; }

    .status { display: inline-block; padding: 4px 8px; border: 1px solid #d8d5ce; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; background: #faf9f5; }
    .status.live { border-color: #111; background: #f4f2ec; }
    .status.draft { border-color: #aaa; background: #faf9f5; color: #888; }

    .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; }
    .gallery-grid .item { position: relative; border: 1px solid #d8d5ce; background: #faf9f5; padding: 6px; }
    .gallery-grid .item img { width: 100%; height: 80px; object-fit: cover; display: block; }
    .gallery-grid .item form { position: absolute; top: 4px; right: 4px; }
    .gallery-grid .item button { background: #111; color: #fff; border: none; padding: 3px 6px; font-size: 9px; cursor: pointer; text-transform: uppercase; letter-spacing: .4px; }

    .inline-form { display: inline; }
    .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .row-actions form { display: inline; }
    .row-actions button { background: #faf9f5; border: 1px solid #d8d5ce; padding: 6px 10px; font-size: 10px; cursor: pointer; text-transform: uppercase; letter-spacing: .4px; }
    .row-actions button.danger { border-color: #b66; color: #800; }
    .row-actions a { background: #faf9f5; border: 1px solid #d8d5ce; padding: 6px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; }

    .empty-note { padding: 24px; text-align: center; color: #777; font-size: 13px; }
    .current-image { margin-top: 6px; font-size: 11px; color: #666; }
    .current-image img { display: block; max-width: 180px; margin-top: 6px; border: 1px solid #d8d5ce; }

    @media (max-width: 980px) {
      .section-grid { grid-template-columns: 1fr; }
      .section { border-right: 0; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <?php $adminTitle = 'Research / blog'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <section class="hero">
      <div class="eyebrow">pastpresentfurther Admin</div>
      <h1 class="headline">Research / Blog Articles</h1>
      <p class="subcopy">
        Create and manage articles shown on the public research page. Upload a main image, write the article content, and add gallery images to enrich each post.
      </p>
      <div class="hero-actions">
        <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
        <a href="dashboard-blogs.php" class="btn">+ New Article</a>
      </div>
    </section>

    <?php if (!$blogsTableReady): ?>
      <div class="alert error">
        Table <code>blogs</code> was not found in the database. Please import the required schema before using this manager.
      </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="section-grid">

      <!-- FORM -->
      <div class="section">
        <div class="section-head"><?= $editBlog ? 'Edit Article' : 'New Article' ?></div>
        <div class="section-body">
          <?php if ($blogsTableReady): ?>
          <form action="dashboard-blogs.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $editBlog ? 'update' : 'create' ?>">
            <?php if ($editBlog): ?>
              <input type="hidden" name="id" value="<?= (int) $editBlog['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
              <label for="title">Title *</label>
              <input type="text" id="title" name="title" class="form-input" required value="<?= htmlspecialchars($editBlog['title'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label for="subtitle">Subtitle</label>
              <input type="text" id="subtitle" name="subtitle" class="form-input" value="<?= htmlspecialchars($editBlog['subtitle'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label for="author">Author</label>
              <input type="text" id="author" name="author" class="form-input" value="<?= htmlspecialchars($editBlog['author'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label for="content">Content *</label>
              <textarea id="content" name="content" class="form-textarea" required><?= htmlspecialchars($editBlog['content'] ?? '') ?></textarea>
              <div class="form-help">Line breaks are preserved on the public page.</div>
            </div>

            <div class="form-group">
              <label for="extra_text">Extra Text (shown below gallery)</label>
              <textarea id="extra_text" name="extra_text" class="form-textarea"><?= htmlspecialchars($editBlog['extra_text'] ?? '') ?></textarea>
              <div class="form-help">Optional. Displayed under the gallery on the article page.</div>
            </div>

            <div class="form-group">
              <label for="main_image">Main Image</label>
              <input type="file" id="main_image" name="main_image" class="form-file" accept="image/*">
              <div class="form-help">JPG / PNG / WEBP / GIF. <?= $editBlog ? 'Leave empty to keep the current image.' : '' ?></div>
              <?php if ($editBlog && !empty($editBlog['main_image'])): ?>
                <div class="current-image">
                  Current:
                  <img src="../<?= htmlspecialchars($editBlog['main_image']) ?>" alt="current main image">
                </div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label for="gallery">Gallery Images</label>
              <input type="file" id="gallery" name="gallery[]" class="form-file" accept="image/*" multiple>
              <div class="form-help">You can upload multiple images at once. <?= $editBlog ? 'New images will be appended to the existing gallery.' : '' ?></div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <button type="submit" class="btn"><?= $editBlog ? 'Update Article' : 'Create Article' ?></button>
              <?php if ($editBlog): ?>
                <a href="dashboard-blogs.php" class="btn-secondary" style="height:44px; display:inline-flex; align-items:center; padding:0 18px; border:1px solid #d8d5ce; text-transform:uppercase; font-size:11px; letter-spacing:.6px;">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
          <?php endif; ?>

          <?php if ($editBlog && $galleryTableReady && !empty($editGallery)): ?>
            <div style="margin-top:24px;">
              <label style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px; color:#555;">Existing Gallery</label>
              <div class="gallery-grid">
                <?php foreach ($editGallery as $g): ?>
                  <div class="item">
                    <img src="../<?= htmlspecialchars($g['image_url']) ?>" alt="gallery">
                    <form method="POST" onsubmit="return confirm('Delete this gallery image?');">
                      <input type="hidden" name="action" value="delete_gallery">
                      <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                      <button type="submit">Delete</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- LIST -->
      <div class="section">
        <div class="section-head">All Articles</div>
        <div class="section-body">
          <?php if (!$blogsTableReady): ?>
            <div class="empty-note">blogs table not available.</div>
          <?php elseif (empty($blogs)): ?>
            <div class="empty-note">No articles yet. Use the form on the left to create the first one.</div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Thumb</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($blogs as $b): ?>
                    <tr>
                      <td>
                        <?php if (!empty($b['main_image'])): ?>
                          <img class="thumb-mini" src="../<?= htmlspecialchars($b['main_image']) ?>" alt="">
                        <?php else: ?>
                          <span class="status draft">No image</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <strong><?= htmlspecialchars($b['title']) ?></strong>
                        <?php if (!empty($b['subtitle'])): ?>
                          <div style="color:#777; font-size:11px;"><?= htmlspecialchars($b['subtitle']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($b['author'] ?? '-') ?></td>
                      <td>
                        <span class="status live">Active</span>
                      </td>
                      <td><?= htmlspecialchars((string)($b['created_at'] ?? '')) ?></td>
                      <td>
                        <div class="row-actions">
                          <a href="dashboard-blogs.php?edit=<?= (int) $b['id'] ?>">Edit</a>
                          <form method="POST" onsubmit="return confirm('Delete this article and all its gallery images?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button type="submit" class="danger">Delete</button>
                          </form>
                          <a href="../detail.php?id=<?= (int) $b['id'] ?>" target="_blank">View</a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </section>
  </main>

</body>
</html>

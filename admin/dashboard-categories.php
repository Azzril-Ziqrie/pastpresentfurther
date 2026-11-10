<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';

function columnExistsCat(PDO $pdo, string $table, string $column): bool {
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $s->execute(['t' => $table, 'c' => $column]);
        return ((int) $s->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
$hasSizeCol = columnExistsCat($pdo, 'categories', 'has_size');
$alterError = '';

if (!$hasSizeCol) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN has_size TINYINT(1) DEFAULT 0");
        $hasSizeCol = true;
    } catch (Throwable $e) {
        $alterError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $hasSize = isset($_POST['has_size']) ? 1 : 0;

        if ($name !== '' && $slug !== '') {
            try {
                if ($hasSizeCol) {
                    $stmt = $pdo->prepare("INSERT INTO categories (name, slug, has_size) VALUES (:name, :slug, :has_size)");
                    $stmt->execute(['name' => $name, 'slug' => $slug, 'has_size' => $hasSize]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO categories (name, slug) VALUES (:name, :slug)");
                    $stmt->execute(['name' => $name, 'slug' => $slug]);
                }
                $message = "Category '{$name}' added successfully.";
            } catch (Throwable $e) {
                $message = "Error: Could not add category. The slug might already exist.";
            }
        } else {
            $message = "Error: Name and Slug are required.";
        }

    } elseif ($_POST['action'] === 'edit_category') {
        $catId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $hasSize = isset($_POST['has_size']) ? 1 : 0;

        if ($catId <= 0) {
            $message = "Error: Invalid category.";
        } elseif ($name === '' || $slug === '') {
            $message = "Error: Name and Slug are required.";
        } else {
            try {
                if ($hasSizeCol) {
                    $stmt = $pdo->prepare(
                        "UPDATE categories SET name = :name, slug = :slug, has_size = :has_size WHERE id = :id"
                    );
                    $stmt->execute(['name' => $name, 'slug' => $slug, 'has_size' => $hasSize, 'id' => $catId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE categories SET name = :name, slug = :slug WHERE id = :id");
                    $stmt->execute(['name' => $name, 'slug' => $slug, 'id' => $catId]);
                }
                $message = "Category '{$name}' updated successfully.";
            } catch (Throwable $e) {
                $message = "Error: Could not update category. The slug might already be used by another category.";
            }
        }
    }
}

$selectCols = $hasSizeCol ? 'id, name, slug, has_size, created_at' : 'id, name, slug, created_at';
$categories = $pdo->query("SELECT {$selectCols} FROM categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Categories — pastpresentfurther Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #ffffff;
      color: #111;
    }
    a { color: inherit; text-decoration: none; }

    /* ── TOPBAR ───────────────────────────────────────── */
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
    .logo {
      width: 140px;
      justify-self: center;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .logo img {
      width: 100%;
      height: 36px;
      object-fit: contain;
    }
    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    /* ── WRAPPER ──────────────────────────────────────── */
    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      border-left: 1px solid #d8d5ce;
      border-right: 1px solid #d8d5ce;
      min-height: calc(100vh - 74px);
    }

    /* ── HERO ─────────────────────────────────────────── */
    .hero {
      padding: 22px 20px;
      border-bottom: 1px solid #d8d5ce;
    }
    .eyebrow {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .7px;
      margin-bottom: 14px;
    }
    .headline {
      margin: 0;
      font-size: 36px;
      line-height: 1.08;
      text-transform: uppercase;
      font-weight: 400;
      max-width: 620px;
    }
    .subcopy {
      margin: 18px 0 0;
      max-width: 560px;
      font-size: 14px;
      line-height: 1.7;
      color: #555;
    }
    .hero-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 24px;
    }
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
      outline: none;
    }
    .btn:hover { background: #000; }
    .btn-secondary {
      background: #faf9f5;
      color: #111;
      border-color: #d8d5ce;
    }
    .btn-secondary:hover { background: #f4f2ec; }
    .btn-small {
      height: 34px;
      padding: 0 12px;
      font-size: 11px;
    }

    /* ── DIAGNOSTIC BOX ───────────────────────────────── */
    .diagnostic {
      margin-top: 18px;
      padding: 14px 18px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      font-size: 12px;
      line-height: 1.8;
    }

    /* ── SECTION GRID ─────────────────────────────────── */
    .section-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
    }
    .section {
      min-width: 0;
      border-right: 1px solid #d8d5ce;
      border-bottom: 1px solid #d8d5ce;
    }
    .section:nth-child(2n) { border-right: 0; }
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
    .section-body {
      padding: 20px;
      background: #ffffff;
    }

    /* ── FORMS ────────────────────────────────────────── */
    .form-group { margin-bottom: 20px; }
    .form-label {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-bottom: 8px;
      color: #333;
    }
    .form-input {
      width: 100%;
      height: 44px;
      padding: 0 14px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      font-size: 13px;
      font-family: inherit;
      outline: none;
      transition: border-color .2s;
    }
    .form-input:focus { border-color: #111; background: #fff; }
    .form-hint {
      font-size: 11px;
      color: #666;
      margin-top: 6px;
      display: block;
    }
    .alert {
      padding: 14px 18px;
      background: #111;
      color: #fff;
      font-size: 12px;
      letter-spacing: .6px;
      text-transform: uppercase;
      margin-bottom: 20px;
    }
    .alert.error {
      background: #faf9f5;
      color: #111;
      border: 1px solid #d8d5ce;
    }

    /* ── TABLE ────────────────────────────────────────── */
    .table-wrap {
      overflow: auto;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    table { width: 100%; border-collapse: collapse; min-width: 480px; }
    th, td {
      border-bottom: 1px solid #e8e6df;
      padding: 14px 12px;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th {
      text-transform: uppercase;
      letter-spacing: .6px;
      font-size: 12px;
      background: #f4f2ec;
    }

    /* ── STATUS BADGES ────────────────────────────────── */
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border: 1px solid #d8d5ce;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      background: #faf9f5;
    }
    .badge.yes { border-color: #111; background: #f4f2ec; }
    .badge.no  { border-color: #d8d5ce; background: #faf9f5; color: #888; }

    /* ── MODAL EDIT ───────────────────────────────────── */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      z-index: 50;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
      background: #fff;
      width: 100%;
      max-width: 440px;
      border: 1px solid #111;
    }
    .modal-head {
      padding: 16px 20px;
      border-bottom: 1px solid #d8d5ce;
      display: flex;
      align-items: center;
      justify-content: space-between;
      text-transform: uppercase;
      font-size: 13px;
      letter-spacing: .6px;
      background: #faf9f5;
    }
    .modal-close {
      cursor: pointer;
      background: none;
      border: none;
      font-size: 20px;
      line-height: 1;
      color: #111;
    }
    .modal-body { padding: 20px; }

    /* ── RESPONSIVE ───────────────────────────────────── */
    @media (max-width: 980px) {
      .section-grid { grid-template-columns: 1fr; }
      .section { border-right: 0; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 0 16px; }
      .headline { font-size: 28px; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <!-- TOPBAR -->
  <?php $adminTitle = 'Categories'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <!-- MAIN CONTENT -->
  <main class="wrap">

    <!-- HERO -->
    <section class="hero">
      <div class="eyebrow">pastpresentfurther Admin / Categories</div>
      <h1 class="headline">Manage Store Categories</h1>
      <p class="subcopy">
        Add new product categories to organize your store. The "Slug" will be used in the URL link of the storefront.
      </p>
      <div class="hero-actions">
        <a href="dashboard.php" class="btn-secondary">← Back to Dashboard</a>
      </div>

      <div class="diagnostic">
        <strong>Diagnostik kolom has_size:</strong><br>
        Status kolom:
        <?= $hasSizeCol
            ? '<span style="color:#111;font-weight:bold;">DITEMUKAN / OK</span>'
            : '<span style="color:#888;font-weight:bold;">TIDAK ADA</span>' ?>
        <?php if ($alterError !== ''): ?>
          <br>Pesan error saat mencoba menambahkan kolom otomatis:<br>
          <code style="display:block;margin-top:6px;padding:8px;background:#f4f2ec;word-break:break-word;"><?= htmlspecialchars($alterError) ?></code>
        <?php endif; ?>
      </div>
    </section>

    <!-- SECTION GRID -->
    <section class="section-grid">

      <!-- ADD CATEGORY -->
      <div class="section">
        <div class="section-head">Add New Category</div>
        <div class="section-body">

          <?php if ($message !== ''): ?>
            <div class="alert <?= strpos($message, 'Error') !== false ? 'error' : '' ?>">
              <?= htmlspecialchars($message) ?>
            </div>
          <?php endif; ?>

          <form action="" method="POST">
            <input type="hidden" name="action" value="add_category">

            <div class="form-group">
              <label class="form-label" for="name">Category Name</label>
              <input type="text" id="name" name="name" class="form-input" placeholder="e.g. Jacket" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="slug">URL Slug</label>
              <input type="text" id="slug" name="slug" class="form-input" placeholder="e.g. jacket" required>
              <span class="form-hint">Must be lowercase and use hyphens instead of spaces (no symbols).</span>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:8px;">
              <input type="checkbox" id="has_size" name="has_size" value="1" style="width:18px;height:18px;cursor:pointer;accent-color:#111;">
              <label class="form-label" for="has_size" style="margin-bottom:0;cursor:pointer;text-transform:none;font-size:13px;">
                Kategori ini memiliki pilihan Size (Ukuran)?
              </label>
            </div>

            <button type="submit" class="btn" style="width:100%;">Save Category</button>
          </form>
        </div>
      </div>

      <!-- EXISTING CATEGORIES -->
      <div class="section">
        <div class="section-head">Existing Categories</div>
        <div class="section-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Slug</th>
                  <?php if ($hasSizeCol): ?><th>Has Size?</th><?php endif; ?>
                  <th>Created At</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$categories): ?>
                  <tr><td colspan="6">No categories found.</td></tr>
                <?php else: ?>
                  <?php foreach ($categories as $cat): ?>
                    <tr>
                      <td><?= (int) $cat['id'] ?></td>
                      <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                      <td><?= htmlspecialchars($cat['slug']) ?></td>
                      <?php if ($hasSizeCol): ?>
                        <td>
                          <?php if (isset($cat['has_size']) && (int)$cat['has_size'] === 1): ?>
                            <span class="badge yes">Yes</span>
                          <?php else: ?>
                            <span class="badge no">No</span>
                          <?php endif; ?>
                        </td>
                      <?php endif; ?>
                      <td><?= htmlspecialchars((string) $cat['created_at']) ?></td>
                      <td>
                        <button
                          type="button"
                          class="btn-secondary btn-small js-edit-category"
                          data-id="<?= (int) $cat['id'] ?>"
                          data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                          data-slug="<?= htmlspecialchars($cat['slug'], ENT_QUOTES) ?>"
                          data-has-size="<?= isset($cat['has_size']) && (int)$cat['has_size'] === 1 ? '1' : '0' ?>"
                        >Edit</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </section>
  </main>

  <!-- MODAL EDIT CATEGORY -->
  <div class="modal-overlay" id="editModal">
    <div class="modal-box">
      <div class="modal-head">
        <span>Edit Category</span>
        <button type="button" class="modal-close" id="editModalClose">&times;</button>
      </div>
      <div class="modal-body">
        <form action="" method="POST">
          <input type="hidden" name="action" value="edit_category">
          <input type="hidden" name="category_id" id="edit_category_id" value="">

          <div class="form-group">
            <label class="form-label" for="edit_name">Category Name</label>
            <input type="text" id="edit_name" name="name" class="form-input" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="edit_slug">URL Slug</label>
            <input type="text" id="edit_slug" name="slug" class="form-input" required>
            <span class="form-hint">Must be lowercase and use hyphens instead of spaces (no symbols).</span>
          </div>

          <?php if ($hasSizeCol): ?>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="edit_has_size" name="has_size" value="1" style="width:18px;height:18px;cursor:pointer;accent-color:#111;">
            <label class="form-label" for="edit_has_size" style="margin-bottom:0;cursor:pointer;text-transform:none;font-size:13px;">
              Kategori ini memiliki pilihan Size (Ukuran)?
            </label>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn" style="width:100%;">Update Category</button>
        </form>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // Auto-generate slug dari nama
      const nameInput = document.getElementById('name');
      const slugInput = document.getElementById('slug');
      if (nameInput && slugInput) {
        nameInput.addEventListener('input', function () {
          slugInput.value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        });
      }

      // Edit Modal
      const editModal   = document.getElementById('editModal');
      const editClose   = document.getElementById('editModalClose');
      const editId      = document.getElementById('edit_category_id');
      const editName    = document.getElementById('edit_name');
      const editSlug    = document.getElementById('edit_slug');
      const editHasSize = document.getElementById('edit_has_size');

      document.querySelectorAll('.js-edit-category').forEach(function (btn) {
        btn.addEventListener('click', function () {
          editId.value   = btn.dataset.id;
          editName.value = btn.dataset.name;
          editSlug.value = btn.dataset.slug;
          if (editHasSize) editHasSize.checked = btn.dataset.hasSize === '1';
          editModal.classList.add('open');
        });
      });

      function closeModal() { editModal.classList.remove('open'); }
      if (editClose) editClose.addEventListener('click', closeModal);
      editModal.addEventListener('click', function (e) {
        if (e.target === editModal) closeModal();
      });
    });
  </script>
</body>
</html>

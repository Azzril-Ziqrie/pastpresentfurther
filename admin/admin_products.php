<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT id, name, thumbnail, is_active FROM products ORDER BY sort_order ASC, id DESC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Product Order — pastpresentfurther</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #efefef;
      color: #111;
    }
    a { color: inherit; text-decoration: none; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #efefef;
      border-bottom: 1px solid #bdbdbd;
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
      width: 92px;
      justify-self: center;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .logo img {
      width: 100%;
      height: 24px;
      object-fit: contain;
    }
    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      border-left: 1px solid #bdbdbd;
      border-right: 1px solid #bdbdbd;
      min-height: calc(100vh - 74px);
    }

    .page-header {
      padding: 22px 24px;
      border-bottom: 1px solid #bdbdbd;
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }
    .eyebrow {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .7px;
      color: #666;
      margin-bottom: 6px;
    }
    .page-header h1 {
      margin: 0;
      font-size: 28px;
      text-transform: uppercase;
      font-weight: 400;
      line-height: 1.1;
    }
    .page-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .btn {
      height: 44px;
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
    }
    .btn-secondary {
      height: 44px;
      border: 1px solid #bdbdbd;
      background: transparent;
      color: #111;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .subnav {
      display: flex;
      border-bottom: 1px solid #bdbdbd;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .subnav-link {
      height: 48px;
      display: inline-flex;
      align-items: center;
      padding: 0 20px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
      border-right: 1px solid #bdbdbd;
      white-space: nowrap;
      color: #666;
      transition: color .15s, background .15s;
    }
    .subnav-link:hover { color: #111; background: #e8e8e8; }
    .subnav-link.active { color: #111; border-bottom: 2px solid #111; background: #fff; }

    .content-body {
      padding: 24px;
    }

    .hint-bar {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      margin-bottom: 20px;
      font-size: 13px;
      color: #555;
    }
    .hint-icon { font-size: 16px; flex-shrink: 0; }

    .table-outer {
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      overflow: auto;
    }
    table { width: 100%; border-collapse: collapse; min-width: 520px; }
    th, td {
      border-bottom: 1px solid #d7d7d7;
      padding: 14px;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th {
      text-transform: uppercase;
      letter-spacing: .6px;
      font-size: 12px;
      background: #f1f1f1;
      font-weight: 600;
    }
    tbody tr:last-child td { border-bottom: 0; }

    .thumb-mini {
      width: 44px;
      height: 54px;
      border: 1px solid #cfcfcf;
      background: #fff;
      object-fit: cover;
      display: block;
    }

    .status {
      display: inline-block;
      padding: 5px 10px;
      border: 1px solid #bdbdbd;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      background: #fff;
    }
    .status.live { border-color: #111; }
    .status.draft { border-color: #bdbdbd; color: #888; }

    .drag-handle {
      cursor: grab;
      font-size: 18px;
      color: #aaa;
      user-select: none;
      text-align: center;
      line-height: 1;
    }
    .drag-handle:active { cursor: grabbing; color: #111; }

    .sortable-ghost { opacity: .35; background: #e6e6e6; }
    .sortable-drag   { background: #fff; box-shadow: 0 6px 20px rgba(0,0,0,.12); }

    .row-count {
      font-size: 12px;
      color: #888;
      text-transform: uppercase;
      letter-spacing: .5px;
      padding: 10px 14px;
      border-top: 1px solid #d7d7d7;
      background: #f1f1f1;
    }

    #toast {
      visibility: hidden;
      min-width: 220px;
      background: #111;
      color: #fff;
      text-align: center;
      padding: 13px 20px;
      position: fixed;
      z-index: 100;
      right: 28px;
      bottom: 28px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
      opacity: 0;
      transition: opacity .25s linear;
    }
    #toast.show { visibility: visible; opacity: 1; }

    @media (max-width: 640px) {
      .topbar { padding: 0 14px; }
      .page-header { padding: 16px; }
      .page-header h1 { font-size: 22px; }
      .content-body { padding: 14px; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <?php $adminTitle = 'Product order'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <div class="page-header">
      <div>
        <div class="eyebrow">Admin / Products</div>
        <h1>Manage Product Order</h1>
      </div>
      <div class="page-actions">
        <a href="dashboard-product.php" class="btn">Product Manager</a>
        <a href="index.php" class="btn-secondary">← Dashboard</a>
      </div>
    </div>

    <nav class="subnav" aria-label="Admin navigation">
      <a href="index.php" class="subnav-link">Overview</a>
      <a href="dashboard-product.php" class="subnav-link">Products</a>
      <a href="dashboard-orders.php" class="subnav-link">Orders</a>
      <a href="dashboard-size.php" class="subnav-link">Sizes</a>
      <a href="dashboard-categories.php" class="subnav-link">Categories</a>
      <a href="set-diskon.php" class="subnav-link">Discounts</a>
      <a href="send-resi.php" class="subnav-link">Send Resi</a>
      <a href="#" class="subnav-link active">Sort Order</a>
    </nav>

    <div class="content-body">

      <div class="hint-bar">
        <span class="hint-icon">☰</span>
        <span>Drag rows by the handle on the left to reorder products. Changes are saved automatically after each drop.</span>
      </div>

      <div class="table-outer">
        <table>
          <thead>
            <tr>
              <th style="width:48px;">Move</th>
              <th style="width:68px;">Image</th>
              <th>Product Name</th>
              <th style="width:110px;">Status</th>
            </tr>
          </thead>
          <tbody id="sortable-list">
            <?php foreach ($products as $product): ?>
              <tr data-id="<?= $product['id'] ?>">
                <td><span class="drag-handle" title="Drag to reorder">☰</span></td>
                <td>
                  <?php
                    $dbThumb = trim((string)$product['thumbnail']);
                    if ($dbThumb === '') {
                        $thumbPath = '../assets/images/placeholder.png';
                    } else {
                        // ltrim memastikan tidak ada / ganda. 
                        // Jika di DB isinya 'assets/img.jpg', maka jadinya '../assets/img.jpg'
                        $thumbPath = '../' . ltrim($dbThumb, '/');
                    }
                  ?>
                  <img
                    class="thumb-mini"
                    src="<?= htmlspecialchars($thumbPath) ?>"
                    alt="<?= htmlspecialchars($product['name']) ?>"
                    loading="lazy"
                  >
                </td>
                <td><?= htmlspecialchars($product['name']) ?></td>
                <td>
                  <?php if ((int)$product['is_active'] === 1): ?>
                    <span class="status live">Active</span>
                  <?php else: ?>
                    <span class="status draft">Draft</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="row-count"><?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></div>
      </div>

    </div>
  </main>

  <div id="toast">Order updated ✓</div>

  <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('sortable-list');

    Sortable.create(list, {
      handle: '.drag-handle',
      animation: 150,
      ghostClass: 'sortable-ghost',
      dragClass: 'sortable-drag',
      onEnd: function () {
        const rows = list.querySelectorAll('tr');
        const newOrder = Array.from(rows).map(r => r.getAttribute('data-id'));
        saveOrder(newOrder);
      }
    });

    function saveOrder(orderArray) {
      fetch('update_sort.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order: orderArray })
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showToast('Order updated ✓');
        } else {
          alert('Failed to save: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(() => alert('Network error. Please try again.'));
    }

    function showToast(msg) {
      const toast = document.getElementById('toast');
      toast.textContent = msg;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 2500);
    }
  });
  </script>

</body>
</html>

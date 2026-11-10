<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

/* ── change password (POST, CSRF-protected, post/redirect/get) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        $_SESSION['admin_flash'] = ['err', 'Session expired. Please try again.'];
    } else {
        $pwErr = admin_change_password($pdo, (string)($_POST['current_password'] ?? ''), (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
        $_SESSION['admin_flash'] = $pwErr === null ? ['ok', 'Password updated. Use the new password the next time you sign in.'] : ['err', $pwErr];
    }
    header('Location: dashboard.php#security');
    exit;
}
$adminFlash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalActiveProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
$totalDraftProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 0')->fetchColumn();
$totalImages = (int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn();

$totalOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$totalPendingOrders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE payment_status IN ('pending', 'waiting_verification')
")->fetchColumn();
$totalApprovedOrders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE payment_status = 'paid'
")->fetchColumn();
$totalRejectedOrders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE payment_status = 'failed'
")->fetchColumn();

$recentProducts = $pdo->query(
    'SELECT id, name, slug, thumbnail, is_active, created_at
     FROM products
     ORDER BY id DESC
     LIMIT 8'
)->fetchAll(PDO::FETCH_ASSOC);

$recentApprovedOrders = $pdo->query(
    "SELECT
        id,
        order_number,
        full_name,
        email,
        currency,
        grand_total,
        payment_status,
        fulfillment_status,
        payment_verified_at,
        created_at
     FROM orders
     WHERE payment_status = 'paid'
     ORDER BY payment_verified_at DESC, id DESC
     LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

$recentPendingOrders = $pdo->query(
    "SELECT
        id,
        order_number,
        full_name,
        email,
        currency,
        grand_total,
        payment_status,
        fulfillment_status,
        created_at
     FROM orders
     WHERE payment_status IN ('pending', 'waiting_verification')
     ORDER BY id DESC
     LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

function formatDashboardMoney(string $currency, float $amount): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 2, '.', ',');
}

function paymentBadgeClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'paid') return 'paid';
    if ($status === 'waiting_verification') return 'waiting';
    if ($status === 'failed') return 'failed';
    return 'pending';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>pastpresentfurther — Admin Dashboard</title>
  <link rel="icon" type="image/png" href="../assets/images/brand/logo-admin.png">
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <style>
    /* Dashboard-only layout (shared look lives in assets/css/admin.css) */
    .kpi-groups{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:28px}
    .kpi-group{background:#fff;border:1px solid var(--adm-line);border-radius:var(--adm-r);padding:16px 18px 18px}
    .kpi-group__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .kpi-group__head h2{margin:0;font-size:14px;font-weight:650;letter-spacing:-.01em}
    .kpi-group__head a{font-size:13px;font-weight:500;color:var(--adm-accent)}
    .kpi-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
    .kpi{padding:12px 14px;border-radius:12px;background:var(--adm-bg)}
    .kpi span{display:block;font-size:12px;color:var(--adm-mute);margin-bottom:6px}
    .kpi strong{font-size:24px;font-weight:650;letter-spacing:-.02em;line-height:1}
    .kpi.is-alert{background:#fff3df}
    .kpi.is-alert strong{color:var(--adm-warn)}

    .attention{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 24px;padding:14px 18px;border-radius:var(--adm-r);background:#fff3df;border:1px solid rgba(178,106,0,.25)}
    .attention strong{font-weight:650}
    .attention p{margin:2px 0 0;font-size:13px;color:#7a4a00}

    .area{margin-bottom:28px}
    .area__title{display:flex;align-items:baseline;gap:10px;margin:0 0 12px}
    .area__title h2{margin:0;font-size:17px;font-weight:650;letter-spacing:-.02em}
    .area__title span{font-size:13px;color:var(--adm-mute)}
    .tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
    .tile{display:flex;gap:14px;align-items:flex-start;padding:16px;background:#fff;border:1px solid var(--adm-line);border-radius:var(--adm-r);transition:transform .25s var(--adm-ease),box-shadow .25s var(--adm-ease),border-color .2s}
    .tile:hover{transform:translateY(-2px);border-color:var(--adm-line-strong);box-shadow:0 8px 24px rgba(0,0,0,.06)}
    .tile:active{transform:scale(.99);transition-duration:0s}
    .tile__icon{flex:none;width:38px;height:38px;border-radius:10px;background:var(--adm-bg);display:grid;place-items:center;color:var(--adm-ink)}
    .tile h3{margin:0 0 3px;font-size:14px;font-weight:650;letter-spacing:-.01em}
    .tile p{margin:0;font-size:12.5px;line-height:1.5;color:var(--adm-mute)}

    .activity{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .activity .section--wide{grid-column:1/-1}
    .sec{max-width:560px;padding:20px 22px;background:#fff;border:1px solid var(--adm-line);border-radius:var(--adm-r)}
    .sec .form-group{margin-bottom:14px}
    .sec .flash{margin-bottom:16px;padding:10px 12px;border-radius:10px;font-size:13px}
    .sec .flash.ok{background:#e6f5ec;color:#14693a}
    .sec .flash.err{background:#fdeaea;color:#9d1f1f}
    .cust small{display:block;color:var(--adm-faint);font-size:12px}
    @media(max-width:1100px){.kpi-groups,.activity{grid-template-columns:1fr}}
    @media(max-width:560px){.kpi-row{grid-template-columns:repeat(2,minmax(0,1fr))}}
  </style>
</head>
<body class="adm">
<?php
$adminTitle = 'Dashboard';
include __DIR__ . '/../includes/admin-header.php';
$icon = static fn(string $k) => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($adminIcons[$k] ?? '') . '</svg>';
?>
  <main class="wrap">

    <section class="hero" style="grid-template-columns:1fr">
      <div>
        <div class="eyebrow">Overview</div>
        <h1 class="headline">Good to see you.</h1>
        <p class="subcopy">Everything you need to run the store, grouped by what you are trying to do.</p>
        <div class="hero-actions">
          <a href="dashboard-product.php" class="btn">Add a product</a>
          <a href="dashboard-orders.php" class="btn-secondary">Review orders</a>
        </div>
      </div>
    </section>

    <?php if ($totalPendingOrders > 0): ?>
      <div class="attention" role="status">
        <div>
          <strong><?= $totalPendingOrders ?> order<?= $totalPendingOrders === 1 ? '' : 's' ?> waiting for payment review</strong>
          <p>Check the proof of payment, then approve or reject it.</p>
        </div>
        <a href="dashboard-orders.php?status=waiting_verification" class="btn">Review now</a>
      </div>
    <?php endif; ?>

    <!-- KPIs grouped by function -->
    <section class="kpi-groups" aria-label="Key numbers">
      <div class="kpi-group">
        <div class="kpi-group__head"><h2>Sales</h2><a href="dashboard-orders.php">All orders</a></div>
        <div class="kpi-row">
          <div class="kpi"><span>Total orders</span><strong><?= $totalOrders ?></strong></div>
          <div class="kpi<?= $totalPendingOrders > 0 ? ' is-alert' : '' ?>"><span>Waiting review</span><strong><?= $totalPendingOrders ?></strong></div>
          <div class="kpi"><span>Approved</span><strong><?= $totalApprovedOrders ?></strong></div>
          <div class="kpi"><span>Rejected</span><strong><?= $totalRejectedOrders ?></strong></div>
        </div>
      </div>
      <div class="kpi-group">
        <div class="kpi-group__head"><h2>Catalog</h2><a href="dashboard-product.php">All products</a></div>
        <div class="kpi-row">
          <div class="kpi"><span>Products</span><strong><?= $totalProducts ?></strong></div>
          <div class="kpi"><span>Active</span><strong><?= $totalActiveProducts ?></strong></div>
          <div class="kpi"><span>Draft</span><strong><?= $totalDraftProducts ?></strong></div>
          <div class="kpi"><span>Gallery images</span><strong><?= $totalImages ?></strong></div>
        </div>
      </div>
    </section>

    <!-- Work areas -->
    <section class="area" aria-labelledby="areaCatalog">
      <div class="area__title"><h2 id="areaCatalog">Catalog</h2><span>Products, categories, sizes and pricing</span></div>
      <div class="tiles">
        <a href="dashboard-product.php" class="tile"><span class="tile__icon"><?= $icon('package') ?></span><div><h3>Products</h3><p>Create products, upload thumbnail and gallery images, set IDR and USD prices.</p></div></a>
        <a href="dashboard-categories.php" class="tile"><span class="tile__icon"><?= $icon('tag') ?></span><div><h3>Categories</h3><p>Create and manage the categories shown in the storefront menu.</p></div></a>
        <a href="dashboard-size.php" class="tile"><span class="tile__icon"><?= $icon('ruler') ?></span><div><h3>Sizes &amp; stock</h3><p>Add sizes and maintain stock for each size variant.</p></div></a>
        <a href="set-diskon.php" class="tile"><span class="tile__icon"><?= $icon('percent') ?></span><div><h3>Discounts</h3><p>Set a discount and strikethrough price for promotions.</p></div></a>
        <a href="admin_products.php" class="tile"><span class="tile__icon"><?= $icon('sort') ?></span><div><h3>Product order</h3><p>Set the position of products on the home page and shop.</p></div></a>
      </div>
    </section>

    <section class="area" aria-labelledby="areaSales">
      <div class="area__title"><h2 id="areaSales">Sales &amp; fulfilment</h2><span>Payments, orders and shipping receipts</span></div>
      <div class="tiles">
        <a href="dashboard-orders.php" class="tile"><span class="tile__icon"><?= $icon('cart') ?></span><div><h3>Orders</h3><p>Review payment proofs, approve or reject payments, and follow approved orders.</p></div></a>
        <a href="send-resi.php" class="tile"><span class="tile__icon"><?= $icon('truck') ?></span><div><h3>Send resi</h3><p>Send the shipping receipt to customers by email.</p></div></a>
      </div>
    </section>

    <section class="area" aria-labelledby="areaContent">
      <div class="area__title"><h2 id="areaContent">Storefront content</h2><span>What visitors see on the public site</span></div>
      <div class="tiles">
        <a href="dashboard-hero.php" class="tile"><span class="tile__icon"><?= $icon('image') ?></span><div><h3>Hero slides</h3><p>Photos, title, subtitle and button in the home page carousel.</p></div></a>
        <a href="lookbook.php" class="tile"><span class="tile__icon"><?= $icon('book') ?></span><div><h3>Lookbook</h3><p>Manage lookbook years and photos.</p></div></a>
        <a href="dashboard-blogs.php" class="tile"><span class="tile__icon"><?= $icon('file') ?></span><div><h3>Research / blog</h3><p>Write and publish articles with main image and gallery.</p></div></a>
        <a href="dashboard-pages.php" class="tile"><span class="tile__icon"><?= $icon('image') ?></span><div><h3>Page photos</h3><p>Change the photo on the About and Contact pages.</p></div></a>
        <a href="../index.php" class="tile" target="_blank" rel="noopener"><span class="tile__icon"><?= $icon('external') ?></span><div><h3>View storefront</h3><p>Open the public store and check the latest layout.</p></div></a>
      </div>
    </section>

    <!-- Recent activity -->
    <section class="area" aria-labelledby="areaActivity">
      <div class="area__title"><h2 id="areaActivity">Recent activity</h2><span>Latest items that need a look</span></div>
      <div class="activity">

        <div class="section">
          <div class="section-head"><span>Waiting payment review</span><a href="dashboard-orders.php?status=waiting_verification" class="mini-link">View all</a></div>
          <div class="section-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Payment</th></tr></thead>
                <tbody>
                  <?php if (!$recentPendingOrders): ?>
                    <tr><td colspan="4">Nothing waiting. You are all caught up.</td></tr>
                  <?php else: foreach ($recentPendingOrders as $order): ?>
                    <tr>
                      <td><a href="order-detail.php?id=<?= (int) $order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a></td>
                      <td class="cust"><?= htmlspecialchars($order['full_name']) ?><small><?= htmlspecialchars($order['email']) ?></small></td>
                      <td><?= htmlspecialchars(formatDashboardMoney($order['currency'], (float) $order['grand_total'])) ?></td>
                      <td><span class="status <?= paymentBadgeClass($order['payment_status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_status'])) ?></span></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section-head"><span>Approved orders</span><a href="dashboard-orders.php?status=paid" class="mini-link">View all</a></div>
          <div class="section-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Fulfilment</th></tr></thead>
                <tbody>
                  <?php if (!$recentApprovedOrders): ?>
                    <tr><td colspan="4">No approved orders yet.</td></tr>
                  <?php else: foreach ($recentApprovedOrders as $order): ?>
                    <tr>
                      <td><a href="order-detail.php?id=<?= (int) $order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a></td>
                      <td class="cust"><?= htmlspecialchars($order['full_name']) ?><small><?= htmlspecialchars($order['email']) ?></small></td>
                      <td><?= htmlspecialchars(formatDashboardMoney($order['currency'], (float) $order['grand_total'])) ?></td>
                      <td><span class="status"><?= htmlspecialchars((string) $order['fulfillment_status']) ?></span></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="section section--wide">
          <div class="section-head"><span>Latest products</span><a href="dashboard-product.php" class="mini-link">Manage</a></div>
          <div class="section-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Thumb</th><th>Name</th><th>Slug</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                  <?php if (!$recentProducts): ?>
                    <tr><td colspan="5">No products yet.</td></tr>
                  <?php else: foreach ($recentProducts as $item): ?>
                    <tr>
                      <td><img class="thumb-mini" src="../<?= htmlspecialchars($item['thumbnail']) ?>" alt=""></td>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= htmlspecialchars($item['slug']) ?></td>
                      <td><span class="status <?= (int) $item['is_active'] === 1 ? 'live' : 'draft' ?>"><?= (int) $item['is_active'] === 1 ? 'Active' : 'Draft' ?></span></td>
                      <td><?= htmlspecialchars((string) $item['created_at']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </section>

    <!-- Security -->
    <section class="area" id="security" aria-labelledby="areaSecurity">
      <div class="area__title"><h2 id="areaSecurity">Security</h2><span>Change the admin password</span></div>
      <form class="sec" method="post" action="dashboard.php#security" autocomplete="off">
        <?php if ($adminFlash): ?>
          <div class="flash <?= $adminFlash[0] === 'ok' ? 'ok' : 'err' ?>" role="<?= $adminFlash[0] === 'ok' ? 'status' : 'alert' ?>"><?= htmlspecialchars($adminFlash[1]) ?></div>
        <?php endif; ?>
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <div class="form-group">
          <label for="currentPw">Current password</label>
          <input type="password" id="currentPw" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
          <label for="newPw">New password</label>
          <input type="password" id="newPw" name="new_password" required minlength="8" autocomplete="new-password">
          <div class="form-help">At least 8 characters.</div>
        </div>
        <div class="form-group">
          <label for="confirmPw">Confirm new password</label>
          <input type="password" id="confirmPw" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn">Update password</button>
        <a href="?admin_logout=1" class="btn-secondary" style="margin-left:8px">Sign out</a>
      </form>
    </section>

  </main>
</body>
</html>

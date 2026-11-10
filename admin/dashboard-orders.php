<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$status = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'waiting_verification', 'paid', 'failed'];

$sql = "
    SELECT
        id,
        order_number,
        full_name,
        email,
        phone,
        currency,
        grand_total,
        payment_status,
        fulfillment_status,
        payment_proof_path,
        invoice_path,
        payment_verified_at,
        created_at
    FROM orders
";
$params = [];

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    $sql .= " WHERE payment_status = :payment_status";
    $params['payment_status'] = $status;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatAdminMoney(string $currency, float $amount): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 2, '.', ',');
}

function badgeClass(string $status): string
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
  <title>Order Manager — pastpresentfurther Admin</title>
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

    /* ── HERO / HEAD ──────────────────────────────────── */
    .hero {
      padding: 22px 20px;
      border-bottom: 1px solid #d8d5ce;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 24px;
      align-items: end;
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
    }
    .subcopy {
      margin: 12px 0 0;
      max-width: 560px;
      font-size: 14px;
      line-height: 1.7;
      color: #555;
    }

    /* ── FILTER TABS ──────────────────────────────────── */
    .filters {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-self: end;
    }
    .filter {
      min-height: 44px;
      padding: 0 16px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      display: inline-flex;
      align-items: center;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .5px;
      transition: border-color .2s, background .2s;
    }
    .filter:hover { border-color: #111; }
    .filter.active {
      border-color: #111;
      background: #111;
      color: #fff;
    }

    /* ── SECTION BODY ─────────────────────────────────── */
    .section {
      padding: 20px;
    }
    .section-head {
      padding: 18px 20px;
      border-bottom: 1px solid #d8d5ce;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #faf9f5;
    }

    /* ── TABLE ────────────────────────────────────────── */
    .table-wrap {
      overflow: auto;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1080px;
    }
    th, td {
      padding: 14px 12px;
      border-bottom: 1px solid #e8e6df;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th {
      background: #f4f2ec;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
    }

    /* ── STATUS BADGES ────────────────────────────────── */
    .status {
      display: inline-block;
      padding: 6px 10px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: .5px;
    }
    .status.paid    { border-color: #111; background: #f4f2ec; }
    .status.waiting { border-color: #888; background: #f4f2ec; }
    .status.failed  { border-color: #aaa; background: #faf9f5; }
    .status.pending { border-color: #d8d5ce; background: #faf9f5; }

    /* ── ACTION BUTTONS ───────────────────────────────── */
    .mini-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .mini-btn {
      min-height: 36px;
      padding: 0 12px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      transition: background .2s;
    }
    .mini-btn:hover { background: #000; }
    .mini-btn.secondary {
      background: #faf9f5;
      color: #111;
      border-color: #d8d5ce;
    }
    .mini-btn.secondary:hover { background: #f4f2ec; border-color: #111; }

    /* ── RESPONSIVE ───────────────────────────────────── */
    @media (max-width: 980px) {
      .hero { grid-template-columns: 1fr; }
      .filters { align-self: auto; }
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
  <?php $adminTitle = 'Orders'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <!-- MAIN CONTENT -->
  <main class="wrap">

    <!-- HERO -->
    <section class="hero">
      <div>
        <div class="eyebrow">pastpresentfurther Admin / Orders</div>
        <h1 class="headline">Order Manager</h1>
        <p class="subcopy">Review payment proof uploads, approve payments, reject invalid proofs, and monitor approved orders.</p>
      </div>

      <!-- FILTER TABS -->
      <div class="filters">
        <a class="filter <?= $status === '' ? 'active' : '' ?>" href="dashboard-orders.php">All</a>
        <a class="filter <?= $status === 'pending' ? 'active' : '' ?>" href="dashboard-orders.php?status=pending">Pending</a>
        <a class="filter <?= $status === 'waiting_verification' ? 'active' : '' ?>" href="dashboard-orders.php?status=waiting_verification">Waiting Review</a>
        <a class="filter <?= $status === 'paid' ? 'active' : '' ?>" href="dashboard-orders.php?status=paid">Approved</a>
        <a class="filter <?= $status === 'failed' ? 'active' : '' ?>" href="dashboard-orders.php?status=failed">Rejected</a>
      </div>
    </section>

    <!-- TABLE SECTION -->
    <section class="section">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Order</th>
              <th>Customer</th>
              <th>Total</th>
              <th>Payment</th>
              <th>Fulfillment</th>
              <th>Proof</th>
              <th>Invoice</th>
              <th>Created</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr>
                <td colspan="9" style="color:#888;">No orders found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($orders as $order): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($order['order_number']) ?></strong><br>
                    <span style="color:#888;font-size:12px;">#<?= (int) $order['id'] ?></span>
                  </td>
                  <td>
                    <?= htmlspecialchars($order['full_name']) ?><br>
                    <span style="color:#888;font-size:12px;"><?= htmlspecialchars($order['email']) ?></span><br>
                    <span style="color:#888;font-size:12px;"><?= htmlspecialchars($order['phone']) ?></span>
                  </td>
                  <td><?= htmlspecialchars(formatAdminMoney($order['currency'], (float) $order['grand_total'])) ?></td>
                  <td>
                    <span class="status <?= badgeClass($order['payment_status']) ?>">
                      <?= htmlspecialchars($order['payment_status']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="status">
                      <?= htmlspecialchars($order['fulfillment_status']) ?>
                    </span>
                  </td>
                  <td>
                    <?php if (!empty($order['payment_proof_path'])): ?>
                      <a class="mini-btn secondary" href="../<?= htmlspecialchars($order['payment_proof_path']) ?>" target="_blank">View Proof</a>
                    <?php else: ?>
                      <span style="color:#aaa;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($order['invoice_path'])): ?>
                      <a class="mini-btn secondary" href="../<?= htmlspecialchars($order['invoice_path']) ?>" target="_blank">Invoice</a>
                    <?php else: ?>
                      <span style="color:#aaa;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= htmlspecialchars((string) $order['created_at']) ?>
                    <?php if (!empty($order['payment_verified_at'])): ?>
                      <br><span style="color:#888;font-size:12px;">Approved: <?= htmlspecialchars((string) $order['payment_verified_at']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="mini-actions">
                      <a class="mini-btn secondary" href="order-detail.php?id=<?= (int) $order['id'] ?>">Detail</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</body>
</html>

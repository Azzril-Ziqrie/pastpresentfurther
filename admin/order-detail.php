<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    die('Invalid order id.');
}

$orderStmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE id = :id
    LIMIT 1
");
$orderStmt->execute(['id' => $orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}

$itemStmt = $pdo->prepare("
    SELECT *
    FROM order_items
    WHERE order_id = :order_id
    ORDER BY id ASC
");
$itemStmt->execute(['order_id' => $order['id']]);
$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$logsStmt = $pdo->prepare("
    SELECT *
    FROM order_status_logs
    WHERE order_id = :order_id
    ORDER BY id DESC
");
$logsStmt->execute(['order_id' => $order['id']]);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? '')));
if ($paymentStatus === '') {
    $paymentStatus = 'pending';
}

$fulfillmentStatus = strtolower(trim((string) ($order['fulfillment_status'] ?? '')));
if ($fulfillmentStatus === '') {
    $fulfillmentStatus = 'pending';
}

$totalPieces = (int) array_sum(array_column($orderItems, 'quantity'));

$success = trim((string) ($_GET['success'] ?? ''));

function detailMoney(string $currency, float $amount): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 2, '.', ',');
}

function itemSizeLabel(array $item): string
{
    $size = trim((string) ($item['size_name'] ?? ''));
    return $size !== '' ? $size : '-';
}

function statusBadgeClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'paid') return 'paid';
    if ($status === 'waiting_verification') return 'waiting';
    if ($status === 'failed') return 'failed';
    if ($status === 'processing') return 'processing';
    return 'pending';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Detail</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; background: #efefef; color: #111; }
    a { color: inherit; text-decoration: none; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #efefef;
      border-bottom: 1px solid #bdbdbd;
    }
    .topbar__left, .topbar__right { display: flex; align-items: center; gap: 18px; }
    .topbar__right { justify-content: flex-end; }
    .logo { width: 92px; justify-self: center; display: flex; align-items: center; justify-content: center; }
    .logo img { width: 100%; height: 24px; object-fit: contain; }
    .nav-link { font-size: 12px; text-transform: uppercase; letter-spacing: .6px; }

    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      border-left: 1px solid #bdbdbd;
      border-right: 1px solid #bdbdbd;
      min-height: calc(100vh - 74px);
    }

    .head {
      padding: 20px;
      border-bottom: 1px solid #bdbdbd;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .title {
      margin: 0 0 8px;
      font-size: 28px;
      text-transform: uppercase;
      font-weight: 400;
    }
    .sub {
      margin: 0;
      color: #555;
      font-size: 14px;
      line-height: 1.6;
    }

    .notice {
      margin: 20px;
      padding: 14px 16px;
      border: 1px solid #111;
      background: #f8f8f8;
      font-size: 13px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
    }
    .panel {
      border-right: 1px solid #bdbdbd;
      border-bottom: 1px solid #bdbdbd;
    }
    .panel:nth-child(2n) { border-right: 0; }
    .panel-head {
      padding: 16px 20px;
      border-bottom: 1px solid #bdbdbd;
      text-transform: uppercase;
      font-size: 13px;
      letter-spacing: .6px;
    }
    .panel-body {
      padding: 20px;
    }

    .row {
      margin-bottom: 10px;
      line-height: 1.7;
      font-size: 14px;
    }

    .btns {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .btn, .btn-secondary {
      min-height: 46px;
      padding: 0 16px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      cursor: pointer;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .5px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: 0.2s ease;
    }
    .btn:hover { background: #333; }
    .btn-icon {
      width: 46px;
      padding: 0;
      background: #111;
    }
    .btn-secondary {
      background: transparent;
      color: #111;
      border-color: #999;
    }
    .btn-secondary:hover { background: #e0e0e0; }

    .status {
      display: inline-block;
      padding: 6px 10px;
      border: 1px solid #bdbdbd;
      background: #fff;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: .5px;
    }
    .status.paid { border-color: #111; }
    .status.waiting { border-color: #444; background: #f3f3f3; }
    .status.failed { border-color: #999; background: #fafafa; }
    .status.processing { border-color: #111; }
    .status.pending { border-color: #bdbdbd; }


    /* --- ORDER ITEMS (kartu, selebar panel) --- */
    body.adm .panel--wide { grid-column: 1 / -1; border-right: 0; }
    body.adm .panel-head .count { font-weight: 400; color: #86868b; font-size: 13px; }
    body.adm .order-items { list-style: none; margin: 0; padding: 0; }
    body.adm .order-item {
      display: grid;
      grid-template-columns: 64px minmax(0, 1fr) auto;
      gap: 16px;
      align-items: center;
      padding: 16px 0;
      border-bottom: 1px solid #e5e5ea;
    }
    body.adm .order-item:first-child { padding-top: 0; }
    body.adm .order-item:last-child  { border-bottom: 0; padding-bottom: 4px; }
    body.adm .order-item__thumb {
      width: 64px; height: 80px; object-fit: cover;
      border-radius: 10px; background: #f5f5f7; border: 1px solid #e5e5ea;
    }
    body.adm .order-item__thumb--empty { display: block; }
    body.adm .order-item__name  { font-size: 15px; font-weight: 600; letter-spacing: -.01em; }
    body.adm .order-item__slug  { font-size: 12px; color: #86868b; margin-top: 2px; word-break: break-all; }
    body.adm .chip {
      display: inline-block; margin: 8px 6px 0 0; padding: 3px 11px;
      border-radius: 999px; background: #f5f5f7; font-size: 12px; color: #1d1d1f;
    }
    body.adm .chip b { font-weight: 600; }
    body.adm .order-item__money { text-align: right; white-space: nowrap; }
    body.adm .order-item__total { font-size: 15px; font-weight: 600; }
    body.adm .order-item__unit  { font-size: 12px; color: #86868b; margin-top: 2px; }

    body.adm .order-summary { max-width: 360px; margin: 18px 0 0 auto; padding-top: 14px; border-top: 1px solid #e5e5ea; }
    body.adm .order-summary__row { display: flex; justify-content: space-between; gap: 16px; padding: 4px 0; font-size: 13px; color: #6e6e73; }
    body.adm .order-summary__row--grand {
      margin-top: 8px; padding-top: 12px; border-top: 1px solid #1d1d1f;
      font-size: 18px; font-weight: 650; letter-spacing: -.02em; color: #1d1d1f;
    }
    body.adm .order-notes { margin-top: 18px; padding: 12px 14px; border-radius: 10px; background: #f5f5f7; font-size: 13px; }
    body.adm .order-notes__label { font-size: 11px; letter-spacing: .04em; text-transform: uppercase; color: #86868b; margin-bottom: 4px; }

    @media (max-width: 640px) {
      body.adm .order-item { grid-template-columns: 52px minmax(0, 1fr); }
      body.adm .order-item__thumb { width: 52px; height: 66px; }
      body.adm .order-item__money { grid-column: 2; text-align: left; }
    }
    .table-wrap {
      overflow: auto;
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 680px;
    }
    th, td {
      padding: 14px 12px;
      border-bottom: 1px solid #d7d7d7;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th {
      background: #f1f1f1;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
    }

    textarea {
      width: 100%;
      min-height: 100px;
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      padding: 12px;
      resize: vertical;
      margin-bottom: 10px;
      font: inherit;
    }

    .proof-box {
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      padding: 16px;
    }

    @media (max-width: 980px) {
      .grid { grid-template-columns: 1fr; }
      .panel { border-right: 0; }
    }

    /* --- LABEL PENGIRIMAN (cetak 100 x 150 mm) --- */
    .print-label-area { display: none; }

    @media print {
      @page { size: 100mm 150mm; margin: 0; }

      body > *:not(.print-label-area) { display: none !important; }
      html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }

      .print-label-area {
        display: block !important;
        width: 100mm;
        height: 148mm;
        padding: 5mm;
        background: #fff;
        color: #000;
        font-family: -apple-system, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
        overflow: hidden;
        page-break-after: avoid;
        page-break-inside: avoid;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .label {
        height: 100%;
        display: flex;
        flex-direction: column;
        border: .35mm solid #000;
        border-radius: 4mm;
        overflow: hidden;
      }

      .label__section { padding: 4mm 4.5mm; border-bottom: .25mm solid #000; }
      .label__section:last-child { border-bottom: 0; }

      .label__eyebrow {
        margin: 0 0 1.2mm;
        font-size: 6.5pt;
        font-weight: 600;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: #444;
      }

      /* Kurir + nomor order */
      .label__top { display: flex; justify-content: space-between; align-items: flex-end; gap: 4mm; }
      .label__courier { margin: 0; font-size: 20pt; font-weight: 700; letter-spacing: -.03em; line-height: 1; text-transform: uppercase; }
      .label__service { margin: 1.2mm 0 0; font-size: 8pt; color: #444; }
      .label__order   { text-align: right; font-size: 7.5pt; line-height: 1.35; }
      .label__order strong { display: block; font-size: 8.5pt; letter-spacing: .02em; }

      /* Penerima & pengirim */
      .label__receiver-name { margin: 0 0 1mm; font-size: 14pt; font-weight: 700; letter-spacing: -.02em; line-height: 1.15; }
      .label__text { margin: 0; font-size: 9.5pt; line-height: 1.4; }
      .label__sender { display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; font-size: 8pt; }
      .label__sender strong { font-size: 8.5pt; letter-spacing: .06em; }

      /* Isi paket */
      .label__items { flex: 1; min-height: 0; overflow: hidden; }
      .label__items-head { display: flex; justify-content: space-between; align-items: baseline; }
      .label__list { list-style: none; margin: 0; padding: 0; }
      .label__item {
        display: grid;
        grid-template-columns: 9mm minmax(0, 1fr) auto;
        gap: 2.5mm;
        align-items: baseline;
        padding: 1.6mm 0;
        border-top: .2mm solid #999;
        font-size: 9.5pt;
        line-height: 1.25;
      }
      .label__item:first-child { border-top: 0; }
      .label__qty  { font-weight: 700; font-size: 11pt; }
      .label__name { font-weight: 600; }
      .label__size { font-size: 8.5pt; padding: .4mm 2.2mm; border: .25mm solid #000; border-radius: 99mm; white-space: nowrap; }

      .label__footer { text-align: center; font-size: 7pt; letter-spacing: .04em; color: #333; padding: 2.5mm 4mm; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Order detail'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">
    <section class="head">
      <div>
        <h1 class="title">Order Detail</h1>
        <p class="sub">Order Number: <strong><?= htmlspecialchars($order['order_number']) ?></strong></p>
      </div>
      <button class="btn btn-icon" onclick="window.print()" title="Print Label Pengiriman">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 6 2 18 2 18 9"></polyline>
          <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
          <rect x="6" y="14" width="12" height="8"></rect>
        </svg>
      </button>
    </section>

    <?php if ($success === 'approved'): ?>
      <div class="notice">Payment approved successfully. Stock has been deducted.</div>
    <?php elseif ($success === 'rejected'): ?>
      <div class="notice">Payment has been rejected successfully.</div>
    <?php endif; ?>

    <section class="grid">
      <div class="panel">
        <div class="panel-head">Customer & Payment</div>
        <div class="panel-body">
          <div class="row"><strong>Name:</strong> <?= htmlspecialchars($order['full_name']) ?></div>
          <div class="row"><strong>Email:</strong> <?= htmlspecialchars($order['email']) ?></div>
          <div class="row"><strong>Phone:</strong> <?= htmlspecialchars($order['phone']) ?></div>
          <div class="row"><strong>Address:</strong> <?= htmlspecialchars(trim(($order['address_line_1'] ?? '') . ' ' . ($order['address_line_2'] ?? '') . ', ' . ($order['city'] ?? '') . ', ' . ($order['state'] ?? '') . ', ' . ($order['postal_code'] ?? '') . ', ' . ($order['country_name'] ?? ''))) ?></div>
          <div class="row"><strong>Courier:</strong> <?= htmlspecialchars($order['courier_code']) ?> - <?= htmlspecialchars($order['courier_service']) ?></div>
          <div class="row">
            <strong>Payment Status:</strong>
            <span class="status <?= statusBadgeClass($paymentStatus) ?>"><?= htmlspecialchars($paymentStatus) ?></span>
          </div>
          <div class="row">
            <strong>Fulfillment Status:</strong>
            <span class="status <?= statusBadgeClass($fulfillmentStatus) ?>"><?= htmlspecialchars($fulfillmentStatus) ?></span>
          </div>
          <div class="row"><strong>Grand Total:</strong> <?= htmlspecialchars(detailMoney($order['currency'], (float) $order['grand_total'])) ?></div>
          <div class="row"><strong>Created:</strong> <?= htmlspecialchars((string) $order['created_at']) ?></div>

          <?php if (!empty($order['invoice_path'])): ?>
            <div class="row">
              <a class="btn-secondary" href="../<?= htmlspecialchars($order['invoice_path']) ?>" target="_blank">Open Invoice</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">Payment Proof & Actions</div>
        <div class="panel-body">
          <div class="proof-box">
            <?php if (!empty($order['payment_proof_path'])): ?>
              <p style="margin-top:0;">Payment proof uploaded.</p>
              <div class="btns">
                <a class="btn-secondary" href="../<?= htmlspecialchars($order['payment_proof_path']) ?>" target="_blank">View Payment Proof</a>
              </div>
            <?php else: ?>
              <p style="margin:0;">No payment proof uploaded yet.</p>
            <?php endif; ?>
          </div>

          <?php if (in_array($paymentStatus, ['pending', 'waiting_verification'], true)): ?>
            <div style="margin-top:18px;">
              <form method="POST" action="approve-payment.php" style="margin-bottom:12px;">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="btn">Approve Payment</button>
              </form>

              <form method="POST" action="reject-payment.php">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <textarea name="reason" placeholder="Reason for rejection"></textarea>
                <button type="submit" class="btn-secondary">Reject Payment</button>
              </form>
            </div>
          <?php else: ?>
            <div style="margin-top:18px;" class="row">
              This order has already been processed.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel panel--wide">
        <div class="panel-head">
          <span>Order Items</span>
          <span class="count"><?= count($orderItems) ?> item<?= count($orderItems) === 1 ? '' : 's' ?> &middot; <?= $totalPieces ?> pcs</span>
        </div>
        <div class="panel-body">
          <?php if (!$orderItems): ?>
            <p class="sub">No order items found.</p>
          <?php else: ?>
            <ul class="order-items">
              <?php foreach ($orderItems as $item): ?>
                <li class="order-item">
                  <?php if (!empty($item['thumbnail'])): ?>
                    <img class="order-item__thumb" src="../<?= htmlspecialchars($item['thumbnail']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <span class="order-item__thumb order-item__thumb--empty"></span>
                  <?php endif; ?>

                  <div>
                    <div class="order-item__name"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="order-item__slug"><?= htmlspecialchars($item['product_slug']) ?></div>
                    <span class="chip">Size <b><?= htmlspecialchars(itemSizeLabel($item)) ?></b></span>
                    <span class="chip">Qty <b><?= (int) $item['quantity'] ?></b></span>
                  </div>

                  <div class="order-item__money">
                    <div class="order-item__total"><?= htmlspecialchars(detailMoney($order['currency'], (float) $item['line_total'])) ?></div>
                    <div class="order-item__unit"><?= htmlspecialchars(detailMoney($order['currency'], (float) $item['price'])) ?> each</div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>

            <div class="order-summary">
              <div class="order-summary__row">
                <span>Subtotal</span>
                <span><?= htmlspecialchars(detailMoney($order['currency'], (float) $order['subtotal'])) ?></span>
              </div>
              <div class="order-summary__row">
                <span>Shipping (<?= htmlspecialchars(trim(($order['courier_code'] ?? '') . ' ' . ($order['courier_service'] ?? ''))) ?>)</span>
                <span><?= htmlspecialchars(detailMoney($order['currency'], (float) $order['shipping_cost'])) ?></span>
              </div>
              <div class="order-summary__row order-summary__row--grand">
                <span>Total</span>
                <span><?= htmlspecialchars(detailMoney($order['currency'], (float) $order['grand_total'])) ?></span>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($order['notes'])): ?>
            <div class="order-notes">
              <div class="order-notes__label">Order notes</div>
              <?= nl2br(htmlspecialchars($order['notes'])) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel panel--wide">
        <div class="panel-head">Status Logs</div>
        <div class="panel-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Notes</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$logs): ?>
                  <tr>
                    <td colspan="4">No logs found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($logs as $log): ?>
                    <tr>
                      <td><?= htmlspecialchars($log['status_type']) ?></td>
                      <td><?= htmlspecialchars($log['status_value']) ?></td>
                      <td><?= htmlspecialchars((string) $log['notes']) ?></td>
                      <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
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

  <!-- LABEL PENGIRIMAN (hanya tampil saat dicetak) -->
  <div class="print-label-area">
    <div class="label">

      <div class="label__section label__top">
        <div>
          <p class="label__eyebrow">Courier</p>
          <p class="label__courier"><?= htmlspecialchars((string) $order['courier_code']) ?></p>
          <p class="label__service"><?= htmlspecialchars((string) $order['courier_service']) ?></p>
        </div>
        <div class="label__order">
          Order No.
          <strong><?= htmlspecialchars($order['order_number']) ?></strong>
        </div>
      </div>

      <div class="label__section">
        <p class="label__eyebrow">Ship to</p>
        <p class="label__receiver-name"><?= htmlspecialchars($order['full_name']) ?></p>
        <p class="label__text">
          <?= htmlspecialchars($order['phone']) ?><br>
          <?= htmlspecialchars(trim(($order['address_line_1'] ?? '') . ' ' . ($order['address_line_2'] ?? ''))) ?><br>
          <?= htmlspecialchars(trim(($order['city'] ?? '') . ', ' . ($order['state'] ?? ''), ' ,')) ?><br>
          <?= htmlspecialchars(trim(($order['postal_code'] ?? '') . ', ' . ($order['country_name'] ?? ''), ' ,')) ?>
        </p>
      </div>

      <div class="label__section label__sender">
        <span class="label__eyebrow" style="margin:0;">From</span>
        <strong>PASTPRESENTFURTHER</strong>
      </div>

      <div class="label__section label__items">
        <div class="label__items-head">
          <p class="label__eyebrow">Package contents</p>
          <p class="label__eyebrow"><?= $totalPieces ?> pcs</p>
        </div>
        <ul class="label__list">
          <?php foreach ($orderItems as $item): ?>
            <li class="label__item">
              <span class="label__qty"><?= (int) $item['quantity'] ?>&times;</span>
              <span class="label__name"><?= htmlspecialchars($item['product_name']) ?></span>
              <span class="label__size">Size <?= htmlspecialchars(itemSizeLabel($item)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="label__section label__footer">Thank you for shopping at PASTPRESENTFURTHER</div>

    </div>
  </div>

</body>
</html>

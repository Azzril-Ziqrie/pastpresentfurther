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
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

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

$success = trim((string) ($_GET['success'] ?? ''));

function detailMoney(string $currency, float $amount): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 2, '.', ',');
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

    /* --- STYLING KHUSUS UNTUK PRINT LABEL --- */
    .print-label-area {
      display: none;
    }

    @media print {
      @page {
        size: 100mm 150mm;
        margin: 0; /* Penting untuk menghilangkan header url & footer page bawaan browser */
      }
      
      /* BENAR-BENAR MENGHILANGKAN ELEMEN WEB AGAR TIDAK ADA BLANK SPACE */
      body > *:not(.print-label-area) {
        display: none !important;
      }
      
      html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
      }
      
      .print-label-area {
        display: block !important;
        width: 100mm;
        height: 148mm; /* Sedikit kurang dari 150mm agar tidak nembus page 2 */
        padding: 4mm;
        box-sizing: border-box;
        background: #fff;
        color: #000;
        font-family: Arial, sans-serif;
        overflow: hidden;
        page-break-after: avoid;
        page-break-inside: avoid;
      }

      .resi-box {
        border: 2px solid #000;
        height: 100%;
        display: flex;
        flex-direction: column;
        border-radius: 6px;
      }

      .resi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid #000;
        padding: 12px;
      }

      .resi-courier {
        font-size: 22px;
        font-weight: 900;
        text-transform: uppercase;
      }

      .resi-order-no {
        font-size: 12px;
        text-align: right;
      }

      .resi-addresses {
        display: flex;
        border-bottom: 2px solid #000;
      }

      .resi-sender, .resi-receiver {
        width: 50%;
        padding: 12px;
      }

      .resi-receiver {
        border-left: 2px solid #000;
      }

      .resi-section-title {
        font-size: 10px;
        text-transform: uppercase;
        color: #555;
        margin-bottom: 6px;
        font-weight: bold;
      }

      .resi-text {
        font-size: 13px;
        line-height: 1.4;
        margin: 0;
      }
      
      .resi-text strong {
        font-size: 16px;
        display: block;
        margin-bottom: 4px;
      }

      .resi-items {
        padding: 12px;
        flex-grow: 1;
      }

      .print-table {
        width: 100%;
        border-collapse: collapse;
        min-width: auto;
        margin-top: 5px;
      }

      .print-table th, .print-table td {
        border: 1px solid #000;
        padding: 6px;
        font-size: 12px;
        text-align: left;
      }

      .print-table th {
        background: #eee !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .resi-footer {
        text-align: center;
        font-size: 11px;
        padding: 8px;
        font-weight: bold;
        border-top: 1px dashed #000;
      }
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

      <div class="panel">
        <div class="panel-head">Order Items</div>
        <div class="panel-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Size</th>
                  <th>Qty</th>
                  <th>Price</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$items): ?>
                  <tr>
                    <td colspan="5">No order items found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($items as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['product_name']) ?></td>
                      <td><?= htmlspecialchars((string) $item['size_name']) ?></td>
                      <td><?= (int) $item['quantity'] ?></td>
                      <td><?= htmlspecialchars(detailMoney($order['currency'], (float) $item['price'])) ?></td>
                      <td><?= htmlspecialchars(detailMoney($order['currency'], (float) $item['line_total'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="panel">
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

  <!-- AREA PRINT LABEL PENGIRIMAN -->
  <div class="print-label-area">
    <div class="resi-box">
      
      <!-- Header Resi -->
      <div class="resi-header">
        <div class="resi-courier">
          <?= htmlspecialchars($order['courier_code']) ?> - <?= htmlspecialchars($order['courier_service']) ?>
        </div>
        <div class="resi-order-no">
          Order No:<br><strong><?= htmlspecialchars($order['order_number']) ?></strong>
        </div>
      </div>

      <!-- Info Pengirim & Penerima -->
      <div class="resi-addresses">
        <div class="resi-sender">
          <div class="resi-section-title">Pengirim:</div>
          <p class="resi-text">
            <strong>PASTPRESENTFURTHER</strong>
            
          </p>
        </div>
        <div class="resi-receiver">
          <div class="resi-section-title">Penerima:</div>
          <p class="resi-text">
            <strong><?= htmlspecialchars($order['full_name']) ?></strong>
            <?= htmlspecialchars($order['phone']) ?><br>
            <?= htmlspecialchars(trim(($order['address_line_1'] ?? '') . ' ' . ($order['address_line_2'] ?? ''))) ?><br>
            <?= htmlspecialchars(trim(($order['city'] ?? '') . ', ' . ($order['state'] ?? ''))) ?><br>
            <?= htmlspecialchars(trim(($order['postal_code'] ?? '') . ', ' . ($order['country_name'] ?? ''))) ?>
          </p>
        </div>
      </div>

      <!-- Ringkasan Barang -->
      <div class="resi-items">
        <div class="resi-section-title">Isi Paket:</div>
        <table class="print-table">
          <thead>
            <tr>
              <th style="width: 15%; text-align: center;">Qty</th>
              <th>Nama Produk</th>
              <th style="width: 25%;">Size</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td style="text-align: center;"><strong><?= (int) $item['quantity'] ?>x</strong></td>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td><?= htmlspecialchars((string) $item['size_name']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="resi-footer">
        Thank you for shopping at PASTPRESENTFURTHER!
      </div>

    </div>
  </div>
  
</body>
</html>

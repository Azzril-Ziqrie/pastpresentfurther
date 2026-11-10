<?php
session_start();

// Tangkap pilihan currency dari URL (sama seperti halaman lain)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/includes/email_helpers.php';

/* ── brand constants ─────────────────────────────────── */
$brandName = 'PASTPRESENTFURTHER';
$brandLogo = 'assets/images/brand/logo.png';

/* ── load order ──────────────────────────────────────── */
$orderNumber = trim((string)($_GET['order'] ?? ''));
if ($orderNumber === '') die('Order number is required.');

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :order_number LIMIT 1");
$stmt->execute(['order_number' => $orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die('Order not found.');

// Currency order sudah tersimpan di kolom `orders.currency` (default 'IDR' untuk legacy).
// Sinkronkan dengan session kalau session aktif USD tapi order IDR (atau sebaliknya) —
// supaya display konsisten dengan checkout.
$orderCurrency = strtoupper((string)($order['currency'] ?? 'IDR'));
if (!in_array($orderCurrency, ['IDR', 'USD'], true)) $orderCurrency = 'IDR';

if (isset($_SESSION['currency']) && in_array(strtoupper($_SESSION['currency']), ['IDR', 'USD'])) {
    $currentCurrency = strtoupper($_SESSION['currency']);
} else {
    $currentCurrency = $orderCurrency;
}

$error = '';

/* ── handle upload (bank / QRIS) ─────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_proof'])) {
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload payment proof.';
    } else {
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        $tmpPath = $_FILES['payment_proof']['tmp_name'];
        $mime    = mime_content_type($tmpPath);

        if (!isset($allowedMime[$mime])) {
            $error = 'Only JPG, PNG, or PDF files are allowed.';
        } else {
            $ext          = $allowedMime[$mime];
            $dir          = __DIR__ . '/uploads/payment-proofs';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $filename     = 'proof-' . $order['order_number'] . '-' . time() . '.' . $ext;
            $absolutePath = $dir . '/' . $filename;
            $relativePath = 'uploads/payment-proofs/' . $filename;

            if (!move_uploaded_file($tmpPath, $absolutePath)) {
                $error = 'Failed to save uploaded file.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("
                        UPDATE orders
                        SET payment_proof_path = :p,
                            payment_status      = 'waiting_verification',
                            updated_at          = NOW()
                        WHERE id = :id
                    ")->execute([
                        'p'  => $relativePath,
                        'id' => $order['id']
                    ]);

                    $pdo->prepare("
                        INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
                        VALUES (:oid, :st, :sv, :n)
                    ")->execute([
                        'oid' => $order['id'],
                        'st'  => 'payment',
                        'sv'  => 'waiting_verification',
                        'n'   => 'Customer uploaded payment proof.'
                    ]);

                    $pdo->commit();

                    $updStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
                    $updStmt->execute(['id' => $order['id']]);
                    $updatedOrder = $updStmt->fetch(PDO::FETCH_ASSOC) ?: $order;

                    $emailSent = sendPPFInvoiceEmail($pdo, $updatedOrder);
                    header(
                        'Location: index.php?payment_uploaded=1&invoice_email='
                        . ($emailSent ? 'sent' : 'failed')
                        . '&order=' . urlencode($orderNumber)
                    );
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Failed to process payment proof: ' . $e->getMessage();
                }
            }
        }
    }
}

function formatMoney($amount, string $currency = 'IDR'): string
{
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
    return 'RP ' . number_format((float)$amount, 0, ',', '.');
}

// Rate konversi USD (sinkron dengan checkout.php)
$usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000;

// Kalau session currency berbeda dari orderCurrency, konversi nilai display
// (DB tetap menyimpan nilai asli sesuai currency order — tidak diubah).
function convertForDisplay(float $amount, string $fromCurrency, string $toCurrency, float $rate): float
{
    if ($fromCurrency === $toCurrency) return $amount;
    if ($fromCurrency === 'IDR' && $toCurrency === 'USD') return $amount / $rate;
    if ($fromCurrency === 'USD' && $toCurrency === 'IDR') return $amount * $rate;
    return $amount;
}

$subtotalDisplay = convertForDisplay((float)($order['subtotal'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);
$shippingDisplay = convertForDisplay((float)($order['shipping_cost'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);
$grandDisplay    = convertForDisplay((float)($order['grand_total'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);

$orderItems = [];
try {
    $itemStmt = $pdo->prepare('SELECT product_name, size_name, quantity, line_total, thumbnail FROM order_items WHERE order_id = :oid ORDER BY id ASC');
    $itemStmt->execute(['oid' => $order['id']]);
    $orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $orderItems = []; }

$paymentStatus   = strtoupper(str_replace('_', ' ', $order['payment_status']));
$grandTotalFmt   = formatMoney($grandDisplay, $currentCurrency);
$orderNum        = htmlspecialchars($order['order_number']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title>Payment — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/checkout.css?v=<?= filemtime(__DIR__ . '/assets/css/checkout.css') ?>">
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <header class="co-head">
    <a href="index.php" class="co-back">← Shop</a>
    <a href="index.php" class="co-head__logo" aria-label="<?= htmlspecialchars($brandName) ?>">
      <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
    </a>
    <span class="co-bag" aria-label="Order"><?= $orderNum ?></span>
  </header>

  <div class="co-layout">

    <!-- ═══ LEFT ═══ -->
    <div class="co-left">
      <div class="co-left__in">

        <?php if ($error !== ''): ?>
          <div class="co-notice co-notice--error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($orderCurrency !== $currentCurrency): ?>
          <div class="co-notice co-notice--warn" role="status">
            This order was created in <strong><?= htmlspecialchars($orderCurrency) ?></strong>. Values shown are converted to <strong><?= htmlspecialchars($currentCurrency) ?></strong> for display only — please pay the original amount in <?= htmlspecialchars($orderCurrency) ?> as stored in our records.
          </div>
        <?php endif; ?>

        <div class="pay-meta">
          <div><small>Order</small><strong><?= $orderNum ?></strong></div>
          <div><small>Total</small><strong><?= htmlspecialchars($grandTotalFmt) ?></strong></div>
          <div><small>Status</small><strong><?= htmlspecialchars($paymentStatus) ?></strong></div>
        </div>

        <!-- Payment method (radio accordion) -->
        <section class="co-sec" aria-label="Payment options">
          <div class="co-sec__head"><h2 class="co-sec__title">Payment</h2></div>
          <p class="co-sec__sub">All transactions are secure and encrypted.</p>

          <div class="pay-list" id="payList">

            <div class="pay-item is-open" data-method="bank">
              <label class="pay-head">
                <input type="radio" name="pay_method" value="bank" checked>
                <span class="pay-head__name">Bank transfer — Permata</span>
                <img src="assets/images/payment/permata.png" alt="Permata" class="pay-logo" onerror="this.style.opacity='.2'">
              </label>
              <div class="pay-body">
                <div class="bank-row"><div class="bank-key">Account no.</div><div class="bank-value">9996904145</div></div>
                <div class="bank-row"><div class="bank-key">Account name</div><div class="bank-value">Diki Febriansyah</div></div>
                <div style="margin-top:12px"><button type="button" class="btn-secondary" data-copy="9996904145" data-target="bank-status">Copy number</button></div>
                <div class="copy-status" id="bank-status"></div>
              </div>
            </div>

            <div class="pay-item" data-method="qris">
              <label class="pay-head">
                <input type="radio" name="pay_method" value="qris">
                <span class="pay-head__name">QRIS</span>
                <img src="assets/images/payment/qris-logo.png" alt="QRIS" class="pay-logo" onerror="this.style.opacity='.2'">
              </label>
              <div class="pay-body">
                <p class="pay-text" style="text-align:center">Scan via mobile banking or e-wallet</p>
                <img src="assets/images/payment/qris.jpg" alt="QRIS barcode" class="qris-img">
              </div>
            </div>

            <div class="pay-item" data-method="paypal">
              <label class="pay-head">
                <input type="radio" name="pay_method" value="paypal">
                <span class="pay-head__name">PayPal</span>
                <img src="assets/images/payment/paypal.png" alt="PayPal" class="pay-logo" onerror="this.style.opacity='.2'">
              </label>
              <div class="pay-body">
                <p class="pay-text">Pay with PayPal securely. When payment is successful, your order will be marked as paid automatically.</p>
                <div id="paypal-button-container"></div>
                <div class="file-preview" id="paypalPreview">PayPal button will appear here.</div>
              </div>
            </div>

          </div>
        </section>

        <!-- Upload proof: only for bank transfer / QRIS -->
        <section class="co-sec" id="uploadSection" aria-label="Upload payment proof">
          <div class="co-sec__head"><h2 class="co-sec__title">Payment proof</h2></div>
          <p class="co-sec__sub">Upload a clear receipt or screenshot showing the amount, date and reference number.</p>
          <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-zone">
              <input type="file" name="payment_proof" id="payment_proof" accept=".jpg,.jpeg,.png,.pdf" required aria-label="Payment proof file (JPG, PNG or PDF)">
              <div class="file-preview" id="filePreview">No file selected — JPG, PNG or PDF, max 10MB</div>
            </div>
            <div style="height:14px"></div>
            <button type="submit" class="btn-primary" id="submitBtn">Submit payment proof →</button>
          </form>
          <p class="co-note">After submission your status changes to “Waiting verification”. We will notify you once it is confirmed.</p>
        </section>

        <nav class="co-foot" aria-label="Policies">
          <a href="shipping.php">Shipping &amp; returns</a>
          <a href="legal.php">Legal</a>
          <a href="contact.php">Contact</a>
        </nav>
      </div>
    </div>

    <!-- ═══ RIGHT: SUMMARY ═══ -->
    <aside class="co-right" aria-label="Order summary">
      <button type="button" class="co-sum-toggle" id="sumToggle" aria-expanded="false" aria-controls="sumBody">
        <span class="lbl">Show order summary</span><i class="chev"></i>
        <strong><?= htmlspecialchars($grandTotalFmt) ?></strong>
      </button>

      <div class="co-right__in">
        <div class="co-sum-body" id="sumBody">
          <?php foreach ($orderItems as $it):
            $lineDisplay = convertForDisplay((float)$it['line_total'], $orderCurrency, $currentCurrency, $usdRateFromIdr); ?>
            <div class="co-item">
              <div class="co-thumb">
                <?php if (!empty($it['thumbnail'])): ?><img src="<?= htmlspecialchars($it['thumbnail']) ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="co-badge"><?= (int)$it['quantity'] ?></span>
              </div>
              <div>
                <div class="co-item__name"><?= htmlspecialchars((string)$it['product_name']) ?></div>
                <?php if (!empty($it['size_name'])): ?><div class="co-item__meta">Size: <?= htmlspecialchars((string)$it['size_name']) ?></div><?php endif; ?>
              </div>
              <div class="co-item__price"><?= htmlspecialchars(formatMoney($lineDisplay, $currentCurrency)) ?></div>
            </div>
          <?php endforeach; ?>

          <div class="co-totals">
            <div class="co-total-row"><span>Subtotal</span><span><?= htmlspecialchars(formatMoney($subtotalDisplay, $currentCurrency)) ?></span></div>
            <div class="co-total-row"><span>Shipping</span><span><?= htmlspecialchars(formatMoney($shippingDisplay, $currentCurrency)) ?></span></div>
            <div class="co-total-row co-total-row--grand"><span>Total</span><span><small><?= htmlspecialchars($currentCurrency) ?></small><?= htmlspecialchars($grandTotalFmt) ?></span></div>
          </div>

          <!-- Optional help in a dropdown -->
          <details class="co-howto">
            <summary>How to pay</summary>
            <ol class="co-steps">
              <li>Pay with bank transfer, QRIS or PayPal.</li>
              <li>For bank transfer or QRIS, transfer the exact total shown above.</li>
              <li>Save a clear screenshot of your receipt.</li>
              <li>Upload the proof using the form on the left (bank transfer or QRIS).</li>
              <li>For PayPal, your payment is verified automatically after success.</li>
            </ol>
          </details>
        </div>
      </div>
    </aside>

  </div>

  <script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars(PAYPAL_CLIENT_ID, ENT_QUOTES, 'UTF-8') ?>&currency=USD"></script>

  <script>
  (function () {
    'use strict';

    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2600);
    }

    /* ── PayPal (rendered once, when its option is first selected) ── */
    const orderNumber   = <?= json_encode($order['order_number']) ?>;
    const paypalPreview = document.getElementById('paypalPreview');
    let paypalReady = false;
    function initPaypal() {
      if (paypalReady) return;
      paypalReady = true;
      if (typeof paypal === 'undefined') {
        if (paypalPreview) paypalPreview.textContent = 'PayPal SDK failed to load.';
        console.error('PayPal SDK is not available');
        return;
      }
      paypal.Buttons({
        createOrder: function () {
          if (paypalPreview) paypalPreview.textContent = 'Creating PayPal order...';
          return fetch('paypal-create-order.php?order=' + encodeURIComponent(orderNumber), { method: 'POST' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
              if (data.orderID) {
                if (paypalPreview) paypalPreview.textContent = 'PayPal order created.';
                return data.orderID;
              }
              throw new Error(data.error || 'Failed to create PayPal order.');
            });
        },
        onApprove: function (data) {
          if (paypalPreview) paypalPreview.textContent = 'Capturing PayPal payment...';
          return fetch('paypal-capture-order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_number: orderNumber, paypal_order_id: data.orderID })
          })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.status === 'COMPLETED') {
              var invoiceMail = data.invoiceMail || 'sent';
              window.location.href = 'index.php?payment_uploaded=1'
                + '&invoice_email=' + encodeURIComponent(invoiceMail)
                + '&order=' + encodeURIComponent(orderNumber)
                + '&method=paypal';
              return;
            }
            throw new Error((data.error || 'Failed to capture PayPal payment') + (data.message ? ' (' + data.message + ')' : ''));
          });
        },
        onCancel: function () { if (paypalPreview) paypalPreview.textContent = 'PayPal payment cancelled.'; },
        onError: function (err) {
          if (paypalPreview) paypalPreview.textContent = 'PayPal error: ' + err;
          console.error('PayPal onError:', err);
        }
      }).render('#paypal-button-container');
    }

    /* ── payment method radios ──────────────────────── */
    const uploadSection = document.getElementById('uploadSection');
    function selectMethod(method) {
      document.querySelectorAll('.pay-item').forEach(el => el.classList.toggle('is-open', el.dataset.method === method));
      if (uploadSection) uploadSection.style.display = method === 'paypal' ? 'none' : '';
      if (method === 'paypal') initPaypal();
    }
    document.querySelectorAll('input[name="pay_method"]').forEach(r => {
      r.addEventListener('change', () => selectMethod(r.value));
    });
    selectMethod((document.querySelector('input[name="pay_method"]:checked') || {}).value || 'bank');

    /* ── copy account number ────────────────────────── */
    document.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', async function () {
        const text   = this.dataset.copy;
        const target = document.getElementById(this.dataset.target);
        try {
          if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
          } else {
            const tmp = document.createElement('textarea');
            tmp.value = text; tmp.style.cssText = 'position:fixed;left:-9999px';
            document.body.appendChild(tmp); tmp.focus(); tmp.select();
            document.execCommand('copy'); document.body.removeChild(tmp);
          }
          if (target) target.textContent = 'Copied ✓';
          showToast('Account number copied');
          setTimeout(() => { if (target) target.textContent = ''; }, 2200);
        } catch (e) {
          if (target) target.textContent = 'Failed to copy.';
        }
      });
    });

    /* ── upload preview + submit state ──────────────── */
    const fileInput   = document.getElementById('payment_proof');
    const filePreview = document.getElementById('filePreview');
    if (fileInput && filePreview) {
      fileInput.addEventListener('change', function () {
        if (this.files && this.files.length > 0) {
          const f = this.files[0];
          filePreview.textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
          filePreview.style.color = 'var(--ink)';
        } else {
          filePreview.textContent = 'No file selected — JPG, PNG or PDF, max 10MB';
          filePreview.style.color = '';
        }
      });
    }
    const uploadForm = document.getElementById('uploadForm');
    const submitBtn  = document.getElementById('submitBtn');
    if (uploadForm && submitBtn) {
      uploadForm.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
      });
    }

    /* ── mobile order-summary toggle ────────────────── */
    const sumToggle = document.getElementById('sumToggle');
    const sumBody   = document.getElementById('sumBody');
    if (sumToggle && sumBody) {
      sumToggle.addEventListener('click', () => {
        const open = sumBody.classList.toggle('is-open');
        sumToggle.setAttribute('aria-expanded', open);
        const lbl = sumToggle.querySelector('.lbl');
        if (lbl) lbl.textContent = open ? 'Hide order summary' : 'Show order summary';
      });
    }
  })();
  </script>
</body>
</html>

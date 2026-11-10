<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

const SMTP_HOST       = 'mail.pastpresentfurther.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'studio@pastpresentfurther.com';
const SMTP_PASSWORD   = 'Agacil12345';
const SMTP_ENCRYPTION = 'tls';

const STORE_NAME  = 'pastpresentfurther';
const STORE_EMAIL = 'studio@pastpresentfurther.com';
const STORE_URL   = 'https://pastpresentfurther.com';

$message = '';
$error   = '';

$search              = trim((string) ($_GET['search'] ?? $_POST['search'] ?? ''));
$selectedOrderNumber = trim((string) ($_POST['selected_order'] ?? $_GET['order'] ?? ''));
$trackingNumberInput = trim((string) ($_POST['tracking_number'] ?? ''));
$customCourierInput  = trim((string) ($_POST['custom_courier'] ?? ''));
$customServiceInput  = trim((string) ($_POST['custom_service'] ?? ''));

$order = null;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function orderValue(array $order, string $key, string $fallback = '-'): string
{
    $value = trim((string) ($order[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}

function buildInvoiceUrl(array $order): string
{
    if (empty($order['invoice_path'])) return '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    return $scheme . '://' . $host . '/' . ltrim((string) $order['invoice_path'], '/');
}

function buildShippingEmailTemplate(array $order, string $trackingNumber, string $courier, string $service): string
{
    $customerName = e(orderValue($order, 'full_name', 'Customer'));
    $orderNumber  = e(orderValue($order, 'order_number'));
    $courierSafe  = e($courier);
    $serviceSafe  = e($service);
    $trackingSafe = e($trackingNumber);
    $invoiceUrl   = buildInvoiceUrl($order);

    $invoiceHtml = '';
    if ($invoiceUrl !== '') {
        $invoiceHtml = '
          <tr>
            <td style="padding-top:10px;font-size:14px;line-height:1.7;color:#444;">
              Invoice:
              <a href="' . e($invoiceUrl) . '" style="color:#111;text-decoration:underline;">Download Invoice</a>
            </td>
          </tr>';
    }

    return '
    <!doctype html>
    <html>
    <head><meta charset="utf-8"><title>Your Order Has Been Shipped</title></head>
    <body style="margin:0;padding:0;background:#faf9f5;font-family:Arial,Helvetica,sans-serif;color:#111;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf9f5;padding:30px 16px;">
        <tr>
          <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;background:#ffffff;border:1px solid #d8d5ce;">
               <tr>
                 <td style="padding:24px 24px 18px;border-bottom:1px solid #d8d5ce;text-align:center;">
                   <img src="' . e(rtrim(STORE_URL, '/') . '/assets/images/brand/logo.png') . '" alt="' . e(STORE_NAME) . ' Logo" style="max-height:40px;margin-bottom:12px;">
                   <div style="font-size:11px;letter-spacing:1.8px;text-transform:uppercase;color:#888;margin-bottom:10px;">' . e(STORE_NAME) . '</div>
                   <div style="font-size:26px;line-height:1.1;font-weight:400;text-transform:uppercase;color:#111;letter-spacing:-0.5px;">Shipping Update</div>
                 </td>
               </tr>
              <tr>
                <td style="padding:24px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="font-size:15px;line-height:1.8;color:#333;padding-bottom:14px;">Hi <strong>' . $customerName . '</strong>,</td></tr>
                    <tr><td style="font-size:15px;line-height:1.8;color:#555;padding-bottom:18px;">Your order has been shipped successfully. Below are your shipping details:</td></tr>
                  </table>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:22px;">
                    <tr>
                      <td style="width:180px;padding:12px;border:1px solid #d8d5ce;background:#faf9f5;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#777;">Order Number</td>
                      <td style="padding:12px;border:1px solid #d8d5ce;font-size:14px;color:#111;">' . $orderNumber . '</td>
                    </tr>
                    <tr>
                      <td style="width:180px;padding:12px;border:1px solid #d8d5ce;background:#faf9f5;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#777;">Courier</td>
                      <td style="padding:12px;border:1px solid #d8d5ce;font-size:14px;color:#111;">' . $courierSafe . '</td>
                    </tr>
                    <tr>
                      <td style="width:180px;padding:12px;border:1px solid #d8d5ce;background:#faf9f5;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#777;">Service</td>
                      <td style="padding:12px;border:1px solid #d8d5ce;font-size:14px;color:#111;">' . $serviceSafe . '</td>
                    </tr>
                    <tr>
                      <td style="width:180px;padding:12px;border:1px solid #d8d5ce;background:#faf9f5;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#777;">Tracking Number</td>
                      <td style="padding:12px;border:1px solid #d8d5ce;font-size:16px;color:#111;font-weight:bold;">' . $trackingSafe . '</td>
                    </tr>
                  </table>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
                    <tr><td style="font-size:14px;line-height:1.7;color:#555;">Please keep this tracking number for your shipment reference.</td></tr>
                    ' . $invoiceHtml . '
                  </table>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="font-size:14px;line-height:1.7;color:#555;padding-top:4px;">Thank you for shopping with ' . e(STORE_NAME) . '.</td></tr>
                    <tr><td style="font-size:14px;line-height:1.7;color:#555;padding-top:14px;">Regards,<br><strong>' . e(STORE_NAME) . '</strong><br>' . e(STORE_EMAIL) . '</td></tr>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 24px;border-top:1px solid #d8d5ce;background:#faf9f5;font-size:12px;line-height:1.7;color:#888;text-align:center;">
                  This email was sent automatically by ' . e(STORE_NAME) . '.
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </body>
    </html>';
}

function sendTrackingEmail(array $order, string $trackingNumber, string $courier, string $service): void
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_ENCRYPTION;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(STORE_EMAIL, STORE_NAME);
    $mail->addAddress((string) $order['email'], (string) ($order['full_name'] ?? 'Customer'));
    $mail->addReplyTo(STORE_EMAIL, STORE_NAME);
    $mail->isHTML(true);
    $mail->Subject = 'Your Order Has Been Shipped - ' . ($order['order_number'] ?? '');
    $mail->Body    = buildShippingEmailTemplate($order, $trackingNumber, $courier, $service);
    $mail->AltBody =
        "Hi " . orderValue($order, 'full_name', 'Customer') . ",\n\n" .
        "Your order has been shipped.\n" .
        "Order Number: " . orderValue($order, 'order_number') . "\n" .
        "Courier: $courier\nService: $service\nTracking Number: $trackingNumber\n\n" .
        "Thank you for shopping with " . STORE_NAME . ".";
    $mail->send();
}

// Build order list
$sql    = "SELECT id,order_number,full_name,email,phone,country_name,address_line_1,address_line_2,city,state,postal_code,courier_code,courier_service,currency,grand_total,payment_status,fulfillment_status,invoice_path,created_at FROM orders WHERE payment_status = 'paid'";
$params = [];
if ($search !== '') {
    $sql .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
$sql .= " ORDER BY id DESC LIMIT 100";
$orderListStmt = $pdo->prepare($sql);
$orderListStmt->execute($params);
$orderList = $orderListStmt->fetchAll(PDO::FETCH_ASSOC);

// Load selected order
if ($selectedOrderNumber !== '') {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :order_number LIMIT 1");
    $stmt->execute(['order_number' => $selectedOrderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order && $error === '') $error = 'Order not found.';
}

// Send tracking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_tracking'])) {
    if ($selectedOrderNumber === '') {
        $error = 'Please select an order first.';
    } elseif ($trackingNumberInput === '') {
        $error = 'Tracking number is required.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :order_number LIMIT 1");
        $stmt->execute(['order_number' => $selectedOrderNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $error = 'Order not found.';
        } elseif (empty($order['email'])) {
            $error = 'Customer email not found.';
        } else {
            $courierToUse = $customCourierInput !== '' ? strtoupper($customCourierInput) : strtoupper(orderValue($order, 'courier_code'));
            $serviceToUse = $customServiceInput !== '' ? $customServiceInput : orderValue($order, 'courier_service');
            try {
                sendTrackingEmail($order, $trackingNumberInput, $courierToUse, $serviceToUse);
                $existingNotes = trim((string) ($order['notes'] ?? ''));
                $notesToAppend = 'Tracking email sent at ' . date('Y-m-d H:i:s') . ' | Courier: ' . $courierToUse . ' | Service: ' . $serviceToUse;
                $newNotes      = $existingNotes !== '' ? $existingNotes . "\n" . $notesToAppend : $notesToAppend;
                $update = $pdo->prepare("UPDATE orders SET fulfillment_status = :fulfillment_status, notes = :notes, updated_at = NOW() WHERE id = :id");
                $update->execute(['fulfillment_status' => 'shipped', 'notes' => $newNotes, 'id' => $order['id']]);
                $message = 'Tracking email sent successfully to ' . e((string) $order['email']) . '.';

                // Refresh list
                $orderListStmt = $pdo->prepare($sql);
                $orderListStmt->execute($params);
                $orderList = $orderListStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error = 'Failed to send email: ' . $e->getMessage();
            }
        }
    }
}

$previewCourier = $customCourierInput !== '' ? strtoupper($customCourierInput) : ($order ? strtoupper(orderValue($order, 'courier_code')) : '-');
$previewService = $customServiceInput !== '' ? $customServiceInput : ($order ? orderValue($order, 'courier_service') : '-');
$emailPreviewHtml = '';
if ($order && $trackingNumberInput !== '') {
    $emailPreviewHtml = buildShippingEmailTemplate($order, $trackingNumberInput, $previewCourier, $previewService);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send Resi — pastpresentfurther Admin</title>
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
    .logo img { width: 100%; height: 36px; object-fit: contain; }
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
    }
    .subcopy {
      margin: 14px 0 0;
      max-width: 560px;
      font-size: 14px;
      line-height: 1.7;
      color: #555;
    }
    .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }

    /* ── ALERTS ───────────────────────────────────────── */
    .alert {
      padding: 14px 18px;
      margin: 0 20px 18px;
      background: #111;
      color: #fff;
      font-size: 12px;
      letter-spacing: .6px;
      text-transform: uppercase;
    }
    .alert.error {
      background: #faf9f5;
      color: #111;
      border: 1px solid #d8d5ce;
    }

    /* ── MAIN GRID ────────────────────────────────────── */
    .content {
      padding: 20px;
      display: grid;
      grid-template-columns: 360px 1fr;
      gap: 20px;
      align-items: start;
    }

    /* ── PANELS ───────────────────────────────────────── */
    .panel {
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    .panel-head {
      padding: 18px 20px;
      border-bottom: 1px solid #d8d5ce;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
      background: #faf9f5;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .panel-body { padding: 20px; background: #ffffff; }
    .panel-stack { display: grid; gap: 20px; }

    /* ── FORMS ────────────────────────────────────────── */
    .form-group { margin-bottom: 16px; }
    .form-label {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-bottom: 8px;
      color: #333;
    }
    .form-input,
    .form-select {
      width: 100%;
      min-height: 44px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      padding: 0 14px;
      outline: none;
      color: #111;
      font-family: inherit;
      font-size: 13px;
      transition: border-color .2s, background .2s;
    }
    .form-input:focus, .form-select:focus {
      border-color: #111;
      background: #fff;
    }
    .form-input[readonly] { color: #666; cursor: default; }
    .helper {
      margin-top: 8px;
      font-size: 12px;
      color: #666;
      line-height: 1.6;
    }

    /* ── BUTTONS ──────────────────────────────────────── */
    .btn, .btn-secondary {
      height: 48px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
    }
    .btn:hover { background: #000; }
    .btn-secondary {
      background: #faf9f5;
      color: #111;
      border-color: #d8d5ce;
    }
    .btn-secondary:hover { background: #f4f2ec; }
    .btn-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn-link {
      height: 48px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      color: #111;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn-link:hover { background: #f4f2ec; }

    /* ── INFO TABLE ───────────────────────────────────── */
    .info-table { width: 100%; border-collapse: collapse; }
    .info-table td {
      border: 1px solid #e8e6df;
      padding: 12px;
      vertical-align: top;
      font-size: 13px;
    }
    .info-key {
      width: 160px;
      background: #f4f2ec !important;
      font-size: 11px !important;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: #666;
    }

    /* ── ORDER LIST ───────────────────────────────────── */
    .order-list {
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      max-height: 280px;
      overflow: auto;
    }
    .order-item {
      padding: 12px 14px;
      border-bottom: 1px solid #e8e6df;
      font-size: 13px;
    }
    .order-item:last-child { border-bottom: 0; }
    .order-item strong { display: block; font-size: 13px; margin-bottom: 4px; }
    .order-meta { color: #666; line-height: 1.6; font-size: 12px; }

    /* ── EMAIL PREVIEW ────────────────────────────────── */
    .preview-box {
      border: 1px solid #d8d5ce;
      background: #fff;
      min-height: 380px;
      overflow: hidden;
    }
    .preview-toolbar {
      padding: 14px 18px;
      border-bottom: 1px solid #d8d5ce;
      background: #faf9f5;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .7px;
      color: #666;
    }
    .preview-placeholder {
      padding: 24px 18px;
      color: #888;
      font-size: 13px;
      line-height: 1.7;
    }

    /* ── RESPONSIVE ───────────────────────────────────── */
    @media (max-width: 1080px) {
      .content { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 0 16px; }
      .headline { font-size: 28px; }
      .btn-row { grid-template-columns: 1fr; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <!-- TOPBAR -->
  <?php $adminTitle = 'Send resi'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <!-- MAIN -->
  <main class="wrap">

    <!-- HERO -->
    <section class="hero">
      <div class="eyebrow">pastpresentfurther Admin / Shipping</div>
      <h1 class="headline">Send Tracking Number</h1>
      <p class="subcopy">
        Cari order, pilih order, isi nomor resi, lalu cek preview email sebelum kirim lewat PHPMailer SMTP.
      </p>
      <div class="hero-actions">
        <a href="dashboard.php" class="btn-link">← Back to Dashboard</a>
        <a href="dashboard-orders.php" class="btn-link">Open Orders</a>
      </div>
    </section>

    <!-- ALERTS -->
    <?php if ($message !== ''): ?>
      <div class="alert"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- CONTENT GRID -->
    <div class="content">

      <!-- LEFT: FORM -->
      <section class="panel">
        <div class="panel-head">Search & Send</div>
        <div class="panel-body">

          <!-- Search -->
          <form method="GET" action="" style="margin-bottom:18px;">
            <div class="form-group">
              <label class="form-label" for="search">Search Order</label>
              <input type="text" name="search" id="search" class="form-input"
                     value="<?= e($search) ?>"
                     placeholder="Order number / name / email / phone">
            </div>
            <button type="submit" class="btn-secondary">Search</button>
          </form>

          <!-- Main form -->
          <form method="POST" action="">
            <input type="hidden" name="search" value="<?= e($search) ?>">

            <div class="form-group">
              <label class="form-label" for="selected_order">Select Order</label>
              <select name="selected_order" id="selected_order" class="form-select"
                      onchange="this.form.submit()" required>
                <option value="">— Choose Order —</option>
                <?php foreach ($orderList as $row): ?>
                  <option value="<?= e((string) $row['order_number']) ?>"
                    <?= $selectedOrderNumber === (string) $row['order_number'] ? 'selected' : '' ?>>
                    <?= e((string) $row['order_number']) ?> — <?= e((string) $row['full_name']) ?> — <?= e((string) $row['email']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="helper">Menampilkan maksimal 100 order dengan status <strong>paid</strong>.</div>
            </div>

            <?php if ($order): ?>
              <div class="form-group">
                <label class="form-label">Customer Name</label>
                <input type="text" class="form-input" value="<?= e((string) $order['full_name']) ?>" readonly>
              </div>

              <div class="form-group">
                <label class="form-label">Customer Email</label>
                <input type="text" class="form-input" value="<?= e((string) $order['email']) ?>" readonly>
              </div>

              <div class="form-group">
                <label class="form-label">Courier</label>
                <input type="text" name="custom_courier" class="form-input"
                       value="<?= e($customCourierInput !== '' ? $customCourierInput : (string)($order['courier_code'] ?? '')) ?>"
                       placeholder="JNE / J&T / SiCepat / etc">
              </div>

              <div class="form-group">
                <label class="form-label">Service</label>
                <input type="text" name="custom_service" class="form-input"
                       value="<?= e($customServiceInput !== '' ? $customServiceInput : (string)($order['courier_service'] ?? '')) ?>"
                       placeholder="REG / YES / etc">
              </div>

              <div class="form-group">
                <label class="form-label">Tracking Number / Resi</label>
                <input type="text" name="tracking_number" class="form-input"
                       value="<?= e($trackingNumberInput) ?>"
                       placeholder="Enter tracking number here" required>
              </div>

              <div class="btn-row">
                <button type="submit" class="btn-secondary">Refresh Preview</button>
                <button type="submit" name="send_tracking" value="1" class="btn">Send Email</button>
              </div>
            <?php else: ?>
              <p style="font-size:13px;color:#888;margin-top:4px;">Pilih order dulu untuk menampilkan detail dan preview email.</p>
            <?php endif; ?>
          </form>

          <div class="helper" style="margin-top:16px;">
            SMTP diambil dari konfigurasi di bagian atas file ini.
          </div>
        </div>
      </section>

      <!-- RIGHT: INFO + LIST + PREVIEW -->
      <div class="panel-stack">

        <!-- Order Information -->
        <section class="panel">
          <div class="panel-head">Order Information</div>
          <div class="panel-body" style="padding:0;">
            <?php if (!$order): ?>
              <div style="padding:18px 20px;font-size:13px;color:#888;">Order detail will appear here after you select one.</div>
            <?php else: ?>
              <table class="info-table">
                <tr><td class="info-key">Order Number</td><td><?= e((string) $order['order_number']) ?></td></tr>
                <tr><td class="info-key">Customer</td><td><?= e((string) $order['full_name']) ?></td></tr>
                <tr><td class="info-key">Email</td><td><?= e((string) $order['email']) ?></td></tr>
                <tr><td class="info-key">Phone</td><td><?= e((string) $order['phone']) ?></td></tr>
                <tr><td class="info-key">Courier</td><td><?= e($previewCourier) ?> / <?= e($previewService) ?></td></tr>
                <tr><td class="info-key">Payment</td><td><?= e((string) $order['payment_status']) ?></td></tr>
                <tr><td class="info-key">Fulfillment</td><td><?= e((string) $order['fulfillment_status']) ?></td></tr>
                <tr><td class="info-key">Grand Total</td><td><?= e((string) $order['currency']) ?> <?= number_format((float) $order['grand_total'], 2, '.', ',') ?></td></tr>
                <tr>
                  <td class="info-key">Address</td>
                  <td>
                    <?= e((string) $order['address_line_1']) ?><br>
                    <?= e((string) $order['address_line_2']) ?><br>
                    <?= e((string) $order['city']) ?>, <?= e((string) $order['state']) ?> <?= e((string) $order['postal_code']) ?><br>
                    <?= e((string) $order['country_name']) ?>
                  </td>
                </tr>
                <tr><td class="info-key">Created At</td><td><?= e((string) $order['created_at']) ?></td></tr>
              </table>
            <?php endif; ?>
          </div>
        </section>

        <!-- Matching Orders -->
        <section class="panel">
          <div class="panel-head">Matching Orders</div>
          <div class="panel-body">
            <?php if (!$orderList): ?>
              <p style="font-size:13px;color:#888;">No orders found.</p>
            <?php else: ?>
              <div class="order-list">
                <?php foreach ($orderList as $row): ?>
                  <div class="order-item">
                    <strong><?= e((string) $row['order_number']) ?></strong>
                    <div class="order-meta">
                      <?= e((string) $row['full_name']) ?> — <?= e((string) $row['email']) ?><br>
                      <?= e((string) $row['currency']) ?> <?= number_format((float) $row['grand_total'], 2, '.', ',') ?><br>
                      Payment: <?= e((string) $row['payment_status']) ?> | Fulfillment: <?= e((string) ($row['fulfillment_status'] ?? '-')) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Email Preview -->
        <section class="panel">
          <div class="panel-head">Email Preview</div>
          <div class="panel-body" style="padding:0;">
            <div class="preview-box">
              <div class="preview-toolbar">Live Preview</div>
              <?php if ($emailPreviewHtml === ''): ?>
                <div class="preview-placeholder">
                  Pilih order dan isi nomor resi untuk menampilkan preview email di sini.
                </div>
              <?php else: ?>
                <?= $emailPreviewHtml ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

      </div><!-- end panel-stack -->
    </div><!-- end content -->
  </main>

</body>
</html>

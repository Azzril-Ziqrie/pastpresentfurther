<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function moneyFormatInvoice(float $amount, string $currency): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function invoiceValue($value): string
{
    $value = trim((string) $value);
    return $value !== '' ? htmlspecialchars($value) : '-';
}

function generateInvoicePDF(PDO $pdo, int $orderId): string
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found for invoice.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('Order items not found for invoice.');
    }

    $fullAddress = trim(
        implode(', ', array_filter([
            trim((string) ($order['address_line_1'] ?? '')),
            trim((string) ($order['address_line_2'] ?? '')),
            trim((string) ($order['city'] ?? '')),
            trim((string) ($order['state'] ?? '')),
            trim((string) ($order['postal_code'] ?? '')),
            trim((string) ($order['country_name'] ?? '')),
        ]))
    );

    $createdDate = !empty($order['created_at'])
        ? date('d M Y H:i', strtotime($order['created_at']))
        : '-';

    $paymentStatus = ucwords(str_replace('_', ' ', (string) ($order['payment_status'] ?? '-')));
    $fulfillmentStatus = ucwords(str_replace('_', ' ', (string) ($order['fulfillment_status'] ?? '-')));

    $html = '
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <style>
        * {
          box-sizing: border-box;
        }

        body {
          font-family: DejaVu Sans, sans-serif;
          font-size: 11px;
          color: #111;
          margin: 0;
          padding: 28px;
          background: #ffffff;
        }

        .invoice-wrap {
          width: 100%;
        }

        .topbar {
          border: 1px solid #bdbdbd;
          padding: 18px 20px;
          margin-bottom: 18px;
        }

        .brand-table {
          width: 100%;
          border-collapse: collapse;
        }

        .brand-table td {
          border: none;
          padding: 0;
          vertical-align: middle;
        }

        .brand-mark {
          font-size: 18px;
          font-weight: bold;
          text-transform: uppercase;
          letter-spacing: 1.4px;
          color: #111;
        }

        .brand-right {
          text-align: right;
        }

        .eyebrow {
          font-size: 10px;
          text-transform: uppercase;
          letter-spacing: 1px;
          color: #666;
          margin-bottom: 6px;
        }

        .invoice-title {
          font-size: 24px;
          font-weight: bold;
          letter-spacing: .8px;
          text-transform: uppercase;
          margin: 0 0 6px;
        }

        .invoice-subtitle {
          font-size: 11px;
          color: #444;
          margin: 0;
        }

        .section {
          border: 1px solid #bdbdbd;
          margin-bottom: 18px;
        }

        .section-head {
          padding: 10px 14px;
          border-bottom: 1px solid #bdbdbd;
          font-size: 10px;
          text-transform: uppercase;
          letter-spacing: 1px;
          color: #444;
          background: #f7f7f7;
          font-weight: bold;
        }

        .section-body {
          padding: 0;
        }

        table {
          width: 100%;
          border-collapse: collapse;
        }

        .info-table td {
          border: 1px solid #dcdcdc;
          padding: 10px 12px;
          vertical-align: top;
        }

        .info-label {
          display: block;
          font-size: 9px;
          text-transform: uppercase;
          letter-spacing: .8px;
          color: #666;
          margin-bottom: 4px;
        }

        .info-value {
          font-size: 11px;
          color: #111;
          word-wrap: break-word;
        }

        .items-table th,
        .items-table td {
          border: 1px solid #dcdcdc;
          padding: 10px 12px;
          vertical-align: top;
        }

        .items-table th {
          background: #f3f3f3;
          text-align: left;
          font-size: 10px;
          text-transform: uppercase;
          letter-spacing: .8px;
        }

        .items-table td {
          font-size: 11px;
        }

        .text-center {
          text-align: center;
        }

        .text-right {
          text-align: right;
        }

        .totals-wrap {
          width: 100%;
          margin-top: 14px;
        }

        .totals-table {
          width: 280px;
          margin-left: auto;
          border-collapse: collapse;
        }

        .totals-table td {
          border: 1px solid #dcdcdc;
          padding: 10px 12px;
          font-size: 11px;
        }

        .totals-table .label {
          background: #fafafa;
        }

        .totals-table tr.grand td {
          font-weight: bold;
          font-size: 12px;
          background: #f3f3f3;
        }

        .footer-note {
          margin-top: 20px;
          border: 1px solid #bdbdbd;
          padding: 12px 14px;
          font-size: 10px;
          color: #444;
          line-height: 1.6;
        }
      </style>
    </head>
    <body>
      <div class="invoice-wrap">
        <div class="topbar">
          <table class="brand-table">
            <tr>
              <td>
                <div class="brand-mark">Albrycia</div>
              </td>
              <td class="brand-right">
                <div class="eyebrow">Albrycia Official Invoice</div>
                <div class="invoice-title">Invoice</div>
                <p class="invoice-subtitle">Thank you for shopping with Albrycia.</p>
              </td>
            </tr>
          </table>
        </div>

        <div class="section">
          <div class="section-head">Invoice & Order Information</div>
          <div class="section-body">
            <table class="info-table">
              <tr>
                <td width="25%">
                  <span class="info-label">Order Number</span>
                  <div class="info-value">' . invoiceValue($order['order_number'] ?? '') . '</div>
                </td>
                <td width="25%">
                  <span class="info-label">Invoice Date</span>
                  <div class="info-value">' . invoiceValue($createdDate) . '</div>
                </td>
                <td width="25%">
                  <span class="info-label">Payment Status</span>
                  <div class="info-value">' . invoiceValue($paymentStatus) . '</div>
                </td>
                <td width="25%">
                  <span class="info-label">Fulfillment Status</span>
                  <div class="info-value">' . invoiceValue($fulfillmentStatus) . '</div>
                </td>
              </tr>
            </table>
          </div>
        </div>

        <div class="section">
          <div class="section-head">Customer Information</div>
          <div class="section-body">
            <table class="info-table">
              <tr>
                <td width="50%">
                  <span class="info-label">Full Name</span>
                  <div class="info-value">' . invoiceValue($order['full_name'] ?? '') . '</div>
                </td>
                <td width="50%">
                  <span class="info-label">Email</span>
                  <div class="info-value">' . invoiceValue($order['email'] ?? '') . '</div>
                </td>
              </tr>
              <tr>
                <td width="50%">
                  <span class="info-label">Phone</span>
                  <div class="info-value">' . invoiceValue($order['phone'] ?? '') . '</div>
                </td>
                <td width="50%">
                  <span class="info-label">Shipping Address</span>
                  <div class="info-value">' . invoiceValue($fullAddress) . '</div>
                </td>
              </tr>
            </table>
          </div>
        </div>

        <div class="section">
          <div class="section-head">Order Items</div>
          <div class="section-body">
            <table class="items-table">
              <thead>
                <tr>
                  <th width="36%">Product</th>
                  <th width="16%">Size</th>
                  <th width="12%" class="text-center">Qty</th>
                  <th width="18%" class="text-right">Price</th>
                  <th width="18%" class="text-right">Line Total</th>
                </tr>
              </thead>
              <tbody>';

    foreach ($items as $item) {
        $html .= '
                <tr>
                  <td>' . invoiceValue($item['product_name'] ?? '') . '</td>
                  <td>' . invoiceValue($item['size_name'] ?? '') . '</td>
                  <td class="text-center">' . (int) ($item['quantity'] ?? 0) . '</td>
                  <td class="text-right">' . moneyFormatInvoice((float) ($item['price'] ?? 0), (string) $order['currency']) . '</td>
                  <td class="text-right">' . moneyFormatInvoice((float) ($item['line_total'] ?? 0), (string) $order['currency']) . '</td>
                </tr>';
    }

    $html .= '
              </tbody>
            </table>

            <div class="totals-wrap">
              <table class="totals-table">
                <tr>
                  <td class="label">Subtotal</td>
                  <td class="text-right">' . moneyFormatInvoice((float) ($order['subtotal'] ?? 0), (string) $order['currency']) . '</td>
                </tr>
                <tr>
                  <td class="label">Shipping</td>
                  <td class="text-right">' . moneyFormatInvoice((float) ($order['shipping_cost'] ?? 0), (string) $order['currency']) . '</td>
                </tr>
                <tr class="grand">
                  <td>Grand Total</td>
                  <td class="text-right">' . moneyFormatInvoice((float) ($order['grand_total'] ?? 0), (string) $order['currency']) . '</td>
                </tr>
              </table>
            </div>
          </div>
        </div>

        <div class="footer-note">
          This invoice was generated automatically by Albrycia. Please keep this document for your payment and order reference.
        </div>
      </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = __DIR__ . '/invoices';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'invoice-' . $order['order_number'] . '.pdf';
    $absolutePath = $dir . '/' . $filename;
    $relativePath = 'invoices/' . $filename;

    file_put_contents($absolutePath, $dompdf->output());

    return $relativePath;
}

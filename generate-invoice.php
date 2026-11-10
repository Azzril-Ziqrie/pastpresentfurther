<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const INVOICE_BRAND        = 'Past Present Further';
const INVOICE_THUMB_WIDTH  = 120;
const INVOICE_THUMB_QUALITY = 82;

/* ── Formatting helpers ─────────────────────────────────────── */

function invoiceValue($value): string
{
    $value = trim((string) $value);
    return $value !== '' ? htmlspecialchars($value) : '-';
}

function invoiceMoney(float $amount, string $currency): string
{
    if (strtoupper($currency) === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function invoiceLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : '-'));
}

function invoiceAddressLines(array $order): array
{
    $street = trim(($order['address_line_1'] ?? '') . ' ' . ($order['address_line_2'] ?? ''));
    $region = implode(', ', array_filter([
        trim((string) ($order['city'] ?? '')),
        trim((string) ($order['state'] ?? '')),
    ]));
    $country = implode(', ', array_filter([
        trim((string) ($order['postal_code'] ?? '')),
        trim((string) ($order['country_name'] ?? '')),
    ]));

    return array_values(array_filter([$street, $region, $country]));
}

/** Kecilkan gambar produk jadi data-URI JPEG agar PDF tetap ringan. */
function invoiceThumbnailDataUri(?string $relativePath): ?string
{
    if (!$relativePath || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $absolute = __DIR__ . '/' . ltrim($relativePath, '/');
    if (!is_file($absolute)) {
        return null;
    }

    $source = @imagecreatefromstring((string) file_get_contents($absolute));
    if (!$source) {
        return null;
    }

    $width  = imagesx($source);
    $height = imagesy($source);
    $thumbW = INVOICE_THUMB_WIDTH;
    $thumbH = (int) round($height * ($thumbW / max($width, 1)));

    $thumb = imagecreatetruecolor($thumbW, $thumbH);
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbW, $thumbH, $width, $height);

    ob_start();
    imagejpeg($thumb, null, INVOICE_THUMB_QUALITY);
    $jpeg = (string) ob_get_clean();

    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

/* ── Data access ────────────────────────────────────────────── */

function fetchInvoiceOrder(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found for invoice.');
    }
    return $order;
}

function fetchInvoiceItems(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('Order items not found for invoice.');
    }
    return $items;
}

/* ── HTML sections ──────────────────────────────────────────── */

function invoiceStyles(): string
{
    return '
    @page { margin: 48px 52px; }
    * { box-sizing: border-box; }
    body {
      font-family: Helvetica, "DejaVu Sans", sans-serif;
      font-size: 11px;
      line-height: 1.5;
      color: #1d1d1f;
      margin: 0;
    }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }
    .right  { text-align: right; }
    .center { text-align: center; }

    .brand    { font-size: 10px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; }
    .doc-kind { font-size: 10px; color: #6e6e73; letter-spacing: 1px; text-transform: uppercase; }
    .title    { font-size: 40px; font-weight: bold; letter-spacing: -1.2px; line-height: 1.05; margin: 34px 0 6px; }
    .lead     { font-size: 13px; color: #6e6e73; margin: 0; }

    .meta       { background: #f5f5f7; border-radius: 14px; margin-top: 30px; }
    .meta td    { padding: 16px 18px; }
    .meta .c-order { width: 32%; }
    .meta .c-date  { width: 27%; }
    .meta .c-pay   { width: 23%; }
    .label      { font-size: 9px; color: #6e6e73; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 3px; }
    .value      { font-size: 12px; font-weight: bold; }

    .parties    { margin-top: 30px; }
    .parties td { width: 50%; padding-right: 24px; }
    .party-text { font-size: 11.5px; line-height: 1.6; margin: 0; }
    .party-text strong { font-size: 12px; }
    .muted      { color: #6e6e73; }

    .section-title { font-size: 9px; color: #6e6e73; letter-spacing: .6px; text-transform: uppercase;
                     margin: 34px 0 8px; padding-bottom: 8px; border-bottom: 1px solid #d2d2d7; }

    .items td   { padding: 14px 0; border-bottom: 1px solid #e8e8ed; vertical-align: middle; }
    .thumb      { width: 46px; height: 58px; border-radius: 6px; }
    .item-name  { font-size: 12px; font-weight: bold; }
    .item-meta  { font-size: 10.5px; color: #6e6e73; margin-top: 2px; }

    .totals       { margin-top: 18px; }
    .totals td    { padding: 5px 0; font-size: 11.5px; }
    .totals .grand td { padding-top: 12px; border-top: 1px solid #1d1d1f; font-size: 17px; font-weight: bold; letter-spacing: -.3px; }

    .notes   { margin-top: 26px; font-size: 11px; }
    .footer  { margin-top: 46px; padding-top: 14px; border-top: 1px solid #d2d2d7; font-size: 9.5px; color: #86868b; line-height: 1.6; }
    ';
}

function invoiceHeaderHtml(array $order, string $createdDate): string
{
    return '
    <table>
      <tr>
        <td class="brand">' . INVOICE_BRAND . '</td>
        <td class="doc-kind right">Invoice</td>
      </tr>
    </table>
    <div class="title">Invoice</div>
    <p class="lead">Thank you for your order, ' . invoiceValue($order['full_name'] ?? '') . '.</p>

    <table class="meta">
      <tr>
        <td class="c-order"><div class="label">Order number</div><div class="value">' . invoiceValue($order['order_number'] ?? '') . '</div></td>
        <td class="c-date"><div class="label">Date</div><div class="value">' . invoiceValue($createdDate) . '</div></td>
        <td class="c-pay"><div class="label">Payment</div><div class="value">' . invoiceValue(invoiceLabel((string) ($order['payment_status'] ?? ''))) . '</div></td>
        <td><div class="label">Fulfillment</div><div class="value">' . invoiceValue(invoiceLabel((string) ($order['fulfillment_status'] ?? ''))) . '</div></td>
      </tr>
    </table>';
}

function invoicePartiesHtml(array $order): string
{
    $addressHtml = implode('<br>', array_map('htmlspecialchars', invoiceAddressLines($order)));
    $courier     = trim(($order['courier_code'] ?? '') . ' ' . ($order['courier_service'] ?? ''));

    return '
    <table class="parties">
      <tr>
        <td>
          <div class="label">Billed to</div>
          <p class="party-text">
            <strong>' . invoiceValue($order['full_name'] ?? '') . '</strong><br>
            <span class="muted">' . invoiceValue($order['email'] ?? '') . '<br>' . invoiceValue($order['phone'] ?? '') . '</span>
          </p>
        </td>
        <td>
          <div class="label">Ship to</div>
          <p class="party-text">' . ($addressHtml !== '' ? $addressHtml : '-') . '</p>
          <p class="party-text muted">' . invoiceValue($courier) . '</p>
        </td>
      </tr>
    </table>';
}

function invoiceItemRowHtml(array $item, string $currency): string
{
    $thumbUri  = invoiceThumbnailDataUri($item['thumbnail'] ?? null);
    $thumbHtml = $thumbUri ? '<img class="thumb" src="' . $thumbUri . '">' : '';
    $size      = trim((string) ($item['size_name'] ?? ''));
    $meta      = 'Size ' . ($size !== '' ? htmlspecialchars($size) : '-')
               . ' &nbsp;·&nbsp; Qty ' . (int) ($item['quantity'] ?? 0)
               . ' &nbsp;·&nbsp; ' . invoiceMoney((float) ($item['price'] ?? 0), $currency);

    return '
        <tr>
          <td width="62">' . $thumbHtml . '</td>
          <td>
            <div class="item-name">' . invoiceValue($item['product_name'] ?? '') . '</div>
            <div class="item-meta">' . $meta . '</div>
          </td>
          <td class="right" width="120"><strong>' . invoiceMoney((float) ($item['line_total'] ?? 0), $currency) . '</strong></td>
        </tr>';
}

function invoiceItemsHtml(array $items, string $currency): string
{
    $rows = implode('', array_map(
        fn(array $item): string => invoiceItemRowHtml($item, $currency),
        $items
    ));

    return '
    <div class="section-title">Items</div>
    <table class="items">' . $rows . '</table>';
}

function invoiceTotalsHtml(array $order): string
{
    $currency = (string) $order['currency'];

    return '
    <table class="totals">
      <tr><td width="55%"></td>
          <td class="muted">Subtotal</td>
          <td class="right">' . invoiceMoney((float) ($order['subtotal'] ?? 0), $currency) . '</td></tr>
      <tr><td></td>
          <td class="muted">Shipping</td>
          <td class="right">' . invoiceMoney((float) ($order['shipping_cost'] ?? 0), $currency) . '</td></tr>
      <tr class="grand"><td></td>
          <td>Total</td>
          <td class="right">' . invoiceMoney((float) ($order['grand_total'] ?? 0), $currency) . '</td></tr>
    </table>';
}

function invoiceNotesHtml(array $order): string
{
    $notes = trim((string) ($order['notes'] ?? ''));
    if ($notes === '') {
        return '';
    }
    return '<div class="notes"><div class="label">Order notes</div>' . nl2br(htmlspecialchars($notes)) . '</div>';
}

function invoiceFooterHtml(): string
{
    return '<div class="footer">This invoice was generated automatically by ' . INVOICE_BRAND
         . '. Please keep it as your payment and order reference.</div>';
}

function buildInvoiceHtml(array $order, array $items): string
{
    $createdDate = !empty($order['created_at'])
        ? date('d M Y, H:i', strtotime($order['created_at']))
        : '-';

    return '<!doctype html><html><head><meta charset="utf-8"><style>' . invoiceStyles() . '</style></head><body>'
         . invoiceHeaderHtml($order, $createdDate)
         . invoicePartiesHtml($order)
         . invoiceItemsHtml($items, (string) $order['currency'])
         . invoiceTotalsHtml($order)
         . invoiceNotesHtml($order)
         . invoiceFooterHtml()
         . '</body></html>';
}

/* ── PDF output ─────────────────────────────────────────────── */

function renderInvoicePdf(string $html): string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

function saveInvoicePdf(string $pdfContent, string $orderNumber): string
{
    $dir = __DIR__ . '/invoices';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'invoice-' . $orderNumber . '.pdf';
    file_put_contents($dir . '/' . $filename, $pdfContent);

    return 'invoices/' . $filename;
}

/** Buat invoice PDF untuk sebuah order dan kembalikan path relatifnya. */
function generateInvoicePDF(PDO $pdo, int $orderId): string
{
    $order = fetchInvoiceOrder($pdo, $orderId);
    $items = fetchInvoiceItems($pdo, $orderId);

    $pdf = renderInvoicePdf(buildInvoiceHtml($order, $items));

    return saveInvoicePdf($pdf, (string) $order['order_number']);
}

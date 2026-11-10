<?php
declare(strict_types=1);

if (!defined('PPF_SMTP_HOST')) {
    define('PPF_SMTP_HOST', 'mail.pastpresentfurther.com');
}
if (!defined('PPF_SMTP_PORT')) {
    define('PPF_SMTP_PORT', 587);
}
if (!defined('PPF_SMTP_USERNAME')) {
    define('PPF_SMTP_USERNAME', 'studio@pastpresentfurther.com');
}
if (!defined('PPF_SMTP_PASSWORD')) {
    define('PPF_SMTP_PASSWORD', 'Agacil12345');
}
if (!defined('PPF_SMTP_FROM_EMAIL')) {
    define('PPF_SMTP_FROM_EMAIL', 'studio@pastpresentfurther.com');
}
if (!defined('PPF_SMTP_FROM_NAME')) {
    define('PPF_SMTP_FROM_NAME', 'PASTPRESENTFURTHER');
}
if (!defined('PPF_ADMIN_EMAIL')) {
    define('PPF_ADMIN_EMAIL', '');
}
if (!defined('PPF_SITE_URL')) {
    define('PPF_SITE_URL', 'https://pastpresentfurther.com');
}

function getPPFOrderItemsForEmail(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("
        SELECT oi.product_name, oi.size_name, oi.quantity, oi.price, oi.line_total,
               (SELECT thumbnail FROM products WHERE name = oi.product_name LIMIT 1) AS thumbnail
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ");
    $stmt->execute(['order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function formatPPFEmailMoney($amount): string
{
    return 'RP ' . number_format((float)$amount, 0, ',', '.');
}

function sendPPFInvoiceEmail(PDO $pdo, array $order): bool
{
    $customerEmail = trim((string)($order['email'] ?? ''));
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('Invoice email skipped: invalid email.');
        return false;
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('Invoice email failed: PHPMailer not installed.');
        return false;
    }

    $items = getPPFOrderItemsForEmail($pdo, (int)($order['id'] ?? 0));
    $itemRows = '';

    foreach ($items as $item) {
        $pName = htmlspecialchars((string)($item['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sName = trim((string)($item['size_name'] ?? ''));
        $qty = (int)($item['quantity'] ?? 0);
        $unitPrice = formatPPFEmailMoney($item['price'] ?? 0);
        $lineTotal = formatPPFEmailMoney($item['line_total'] ?? 0);
        $thumbRaw = trim((string)($item['thumbnail'] ?? ''));
        $thumbSrc = '';
        if ($thumbRaw !== '') {
            if (preg_match('#^https?://#i', $thumbRaw)) {
                $thumbSrc = $thumbRaw;
            } else {
                $thumbSrc = rtrim(PPF_SITE_URL, '/') . '/' . ltrim($thumbRaw, '/');
            }
        }

        $sizeHtml = '';
        if ($sName !== '' && !in_array(strtolower($sName), ['one size', 'default'], true)) {
            $sizeHtml = '<div style="font-size:9px;letter-spacing:.1em;color:#888;margin-top:4px;">SIZE: ' . htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $thumbHtml = $thumbSrc !== ''
            ? '<img src="' . htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . $pName . '" width="72" height="96" style="display:block;width:72px;height:96px;object-fit:cover;background:#f4f4f2;border:0;">'
            : '<div style="width:72px;height:96px;background:#f4f4f2;"></div>';

        $itemRows .= '
        <tr>
          <td style="padding:18px 0;border-bottom:1px solid #f0f0f0;">
            <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;">
              <tr>
                <td style="vertical-align:top;padding-right:18px;">' . $thumbHtml . '</td>
                <td style="vertical-align:top;">
                  <div style="font-size:10px;letter-spacing:.1em;color:#111;font-weight:400;text-transform:uppercase;">' . $pName . '</div>
                  ' . $sizeHtml . '
                  <div style="font-size:9px;letter-spacing:.1em;color:#888;margin-top:8px;">QTY: ' . $qty . '</div>
                  <div style="font-size:9px;letter-spacing:.08em;color:#bbb;margin-top:3px;">' . $unitPrice . '</div>
                </td>
              </tr>
            </table>
          </td>
          <td style="padding:18px 0;border-bottom:1px solid #f0f0f0;text-align:right;vertical-align:top;font-size:10px;letter-spacing:.06em;color:#111;white-space:nowrap;">' . $lineTotal . '</td>
        </tr>';
    }

    if ($itemRows === '') {
        $itemRows = '<tr><td colspan="2" style="padding:18px 0;color:#bbb;font-size:10px;letter-spacing:.1em;">ORDER ITEMS ARE BEING PROCESSED.</td></tr>';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = PPF_SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = PPF_SMTP_USERNAME;
        $mail->Password = PPF_SMTP_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = PPF_SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(PPF_SMTP_FROM_EMAIL, PPF_SMTP_FROM_NAME);
        $mail->addAddress($customerEmail, (string)($order['full_name'] ?? 'Customer'));

        $adminEmail = trim(PPF_ADMIN_EMAIL);
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addBCC($adminEmail, 'PPF Admin');
        }

        $orderNumber = htmlspecialchars((string)($order['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fullName = htmlspecialchars((string)($order['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
        $subtotal = formatPPFEmailMoney($order['subtotal'] ?? 0);
        $shipping = formatPPFEmailMoney($order['shipping_cost'] ?? 0);
        $grandTotal = formatPPFEmailMoney($order['grand_total'] ?? 0);
        $address = htmlspecialchars(trim((string)($order['address_line_1'] ?? '') . ' ' . (string)($order['address_line_2'] ?? '')), ENT_QUOTES, 'UTF-8');
        $cityLine = htmlspecialchars(trim((string)($order['city'] ?? '') . ', ' . (string)($order['state'] ?? '') . ' ' . (string)($order['postal_code'] ?? '')), ENT_QUOTES, 'UTF-8');
        $country = htmlspecialchars((string)($order['country_name'] ?? 'Indonesia'), ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'PASTPRESENTFURTHER Order Invoice — ' . (string)($order['order_number'] ?? '');
        $mail->Body = '
        <div style="font-family:Helvetica,Helvetica,Arial,sans-serif;color:#111;background:#f0f0f0;padding:28px;line-height:1.6;letter-spacing:.04em;">
          <div style="max-width:660px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;">

<div style="background:#111;color:#fff;padding:32px 28px;text-align:center;">
  <img src="' . rtrim(PPF_SITE_URL, '/') . '/assets/images/brand/logo.png' . '" alt="PASTPRESENTFURTHER Logo" style="max-height:40px;margin-bottom:12px;">
  <div style="font-size:9px;color:#bbb;margin-top:8px;letter-spacing:.2em;text-transform:uppercase;">Order Invoice</div>
</div>

            <div style="padding:32px 28px;">

              <p style="margin:0 0 6px;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#111;">Hi <strong style="font-weight:400;">' . $fullName . '</strong>,</p>
              <p style="margin:0 0 24px;color:#666;font-size:11px;text-transform:uppercase;letter-spacing:.1em;">Your payment has been received — your order is now being processed.</p>

              <div style="background:#f5f5f5;border:1px solid #eee;padding:18px;margin-bottom:28px;">
                <div style="font-size:9px;color:#999;text-transform:uppercase;letter-spacing:.18em;">Order Number</div>
                <div style="font-size:16px;font-weight:400;margin-top:6px;letter-spacing:.12em;color:#111;">' . $orderNumber . '</div>
              </div>

              <div style="font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#111;padding-bottom:10px;border-bottom:1px solid #111;margin-bottom:4px;">Items</div>
              <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:24px;">' . $itemRows . '</table>

              <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-top:1px solid #eee;padding-top:14px;">
                <tr>
                  <td style="padding:7px 0;color:#666;font-size:10px;letter-spacing:.14em;text-transform:uppercase;">Subtotal</td>
                  <td style="padding:7px 0;text-align:right;font-size:10px;letter-spacing:.06em;">' . $subtotal . '</td>
                </tr>
                <tr>
                  <td style="padding:7px 0;color:#666;font-size:10px;letter-spacing:.14em;text-transform:uppercase;">Shipping</td>
                  <td style="padding:7px 0;text-align:right;font-size:10px;letter-spacing:.06em;">' . $shipping . '</td>
                </tr>
                <tr>
                  <td style="padding:18px 0 0;border-top:1px solid #eee;font-weight:400;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#111;">Grand Total</td>
                  <td style="padding:18px 0 0;border-top:1px solid #eee;text-align:right;font-weight:400;font-size:13px;letter-spacing:.06em;color:#111;">' . $grandTotal . '</td>
                </tr>
              </table>

              <div style="margin-top:28px;padding:18px;border:1px solid #eee;background:#fafafa;">
                <div style="font-size:9px;color:#999;text-transform:uppercase;letter-spacing:.18em;margin-bottom:10px;">Shipping Address</div>
                <div style="font-size:10px;line-height:1.8;text-transform:uppercase;letter-spacing:.1em;color:#111;">' . $address . '<br>' . $cityLine . '<br>' . $country . '</div>
              </div>

              <p style="margin:28px 0 0;color:#666;font-size:10px;text-transform:uppercase;letter-spacing:.12em;">Thank you for shopping with us.</p>
              <p style="margin:18px 0 0;font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#111;">PASTPRESENTFURTHER</p>
            </div>

            <div style="border-top:1px solid #f0f0f0;padding:18px 28px;background:#fafafa;text-align:center;">
              <div style="font-size:8px;letter-spacing:.18em;text-transform:uppercase;color:#bbb;">&copy; ' . date('Y') . ' PASTPRESENTFURTHER &middot; S.L Village Klapanunggal Bogor West Java, 16871</div>
            </div>

          </div>
        </div>';

        $mail->AltBody = 'PASTPRESENTFURTHER Order Invoice — ' . (string)($order['order_number'] ?? '') . '. Total: ' . $grandTotal . '.';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Invoice email failed: ' . $e->getMessage());
        return false;
    }
}

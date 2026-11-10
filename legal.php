<?php
session_start();

// Tangkap pilihan currency dari URL
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

// Batasi panjang search supaya tidak bikin URL/Query panjang & tidak rentan error
if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';

/* ── helpers ─────────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function tableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        return array_map(static fn($row) => $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/* ── schema detection ────────────────────────────────── */
$productColumns     = tableColumns($pdo, 'products');
$hasCategoriesTable = tableExists($pdo, 'categories');
$hasCategoryId      = in_array('category_id',  $productColumns, true);
$hasCategoryText    = in_array('category',      $productColumns, true);

$joinCategory   = $hasCategoriesTable && $hasCategoryId;

/* ── categories (untuk menu navbar) ──────────────────── */
$categories = [];
if ($joinCategory) {
    try { $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try { $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title>Legal — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* Legal page body text is 10px */
.legal-section p,.legal-section li{font-size:10px}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <main class="legal-wrapper">
    <section class="legal-section">
      <h2>OVERVIEW</h2>
      <p>Welcome to PASTPRESENTFURTHER -- --- ----. These Terms & Conditions (“Terms”) govern your use of our website, www.pastpresentfurther.com (“website”), operated by PASTPRESENTFURTHER NIB: 0607250040631. (“we”, “us”, or “our”), a company registered in West Java, Indonesia. By accessing or using this Site, or purchasing from us, you agree to be bound by the following Terms, including any additional policies referenced herein or available by hyperlink. If you do not agree, you may not access the Site or use any services.</p>
    </section>

    <section class="legal-section">
      <h2>TERMS</h2>
      <p>The following terms of use govern the website pastpresentfurther.com. pastpresentfurther.com reserves the right to make corrections and changes to the website when necessary without giving prior notice. pastpresentfurther.com does not offer any guaranty that the information published on its own website, is in conformity with the laws of the User’s competent jurisdiction. pastpresentfurther.com the website of PASTPRESENTFURTHER, is a protected website according to international Internet standards which, if used correctly, Users can be reassured not to encounter viruses. However, PASTPRESENTFURTHER declines any responsibility from eventual problems, damages, viruses or risks that the User may incur during the misuse of the website and declines any responsibility from eventual faulty operations of the website due to the deactivation of “cookies” in the User’s browser. Users must accept the terms of use of this legal notice, and must be sure to visit these pages periodically for updates, changes and corrections. By using pastpresentfurther.com the user agrees to respect and act upon all the terms of use outlined and all other policies on pastpresentfurther.com Users who do not accept these terms are asked to refrain from using the site. We urge users to check back often and stay up to date on the latest updates and legal issues. The use and access of pastpresentfurther.com is for personal use only.</p>
    </section>

    <section class="legal-section">
      <h2>PRIVACY</h2>
      <p>This notice contains the terms and legal conditions that govern the web site pastpresentfurther.com. Users of pastpresentfurther.com accept and agree to respect all the conditions listed in this declaration. If users do not accept these terms, they are asked to refrain from using the website. The access and the use of the website pastpresentfurther.com are for personal use only. All details gathered in the process of accessing and registering to pastpresentfurther.com services are kept private and secure on our servers. Personal details may only be used for user authentication with personnel agencies and for internal research unless otherwise stated. Credit card and payment details may only be shared with the owners bank for authentication. This information is kept in order to provide users with fast browsing options and assistance. NEWSLETTERS – COLLECTION AND USE PERSONAL DATA In subscribing for a newsletter you agree that PASTPRESENTFURTHER may provide you information by email about pastpresentfurther.com website’s news and contents related to its products and promotions. You can unsubscribe from our mailing list by clicking on the unsubscribe link at the bottom of every marketing email and you will be opted out of all email communications. We only collect the following information: Country – Email address – Gender. We transfer your data to our service providers only for data administration and hosting purposes.</p>
    </section>

    <section class="legal-section">
      <h2>SERVICES OFFERED</h2>
      <p>We provide the following services via our Site:</p>
      <ul>
        <li>Product information and online ordering;</li>
        <li>Order processing and delivery;</li>
        <li>Customer service, returns, and refunds;</li>
        <li>Promotions and marketing communication.</li>
      </ul>
      <p>We reserve the right to modify or discontinue any service or product at any time without notice.</p>
    </section>

    <section class="legal-section">
      <h2>PRODUCT INFORMATION & ACCURACY</h2>
      <p>We strive to ensure product descriptions, images, prices, and availability are accurate. However, we do not guarantee that all information is current, complete, or error-free. We may correct inaccuracies or update information at any time without prior notice.</p>
      <p>We reserve the right to refuse or cancel any order placed for a product with inaccurate information, even after the order has been submitted.</p>
    </section>

    <section class="legal-section">
      <h2>PRICING & PAYMENT</h2>
      <p>Prices are listed in your local currency at checkout (where available) and may include applicable duties and taxes for international orders via Global-e.</p>
      <p>We accept a range of secure payment methods including credit/debit cards, PayPal, and other local options depending on your region. PASTPRESENTFURTHER does not charge additional processing fees beyond what is shown at checkout.</p>
    </section>

    <section class="legal-section">
      <h2>COOKIES</h2>
      <p>In particular, PASTPRESENTFURTHER uses Google Analytics, a web analytics service provided by Google inc. (“Google”). Google Analytics uses “cookies”, which are text files that are stored on your computer, to help the website analyse how users use the website. The information generated by the cookie about how you use the website (including your ip address) will be sent to and stored by Google servers in the United States. Google will use this information for the purpose of evaluating your use of the website, compiling reports on website activity for website operators, and providing other services relating to website activity and internet usage. Google may also transfer this information to third parties where required to do so by law, or where said third parties process the information on Google’s behalf. Google will not associate your ip address with any other data held by Google. You may refuse the use of cookies by selecting the appropriate settings on your browser; however, please note that if you do this, you may not be able to use the website’s full features. By using this website, you consent to the processing of your data by Google in the manner and for the purposes set out above.</p>
    </section>

    <section class="legal-section">
      <h2>TEMPORARY SERVICE SUSPENSION</h2>
      <p>We may temporarily suspend access to the website in case of system maintenance, technical updates, server failure, or other reasons beyond our control. Where feasible, we will inform users in advance.</p>
      <p>We are not liable for any damages resulting from such interruptions unless caused by our gross negligence or willful misconduct.</p>
    </section>

    <section class="legal-section">
      <h2>GOOGLE</h2>
      <p>Cookies are small text strings that the sites visited by the user to send to his/her terminal (usually the browser), where they are stored before being sent back to the same websites at the next visit by the same user. While browsing a website, the user can also receive on his/her terminal cookies that are sent by different websites or web server (see “third party”), where some elements can reside (such as, for example, images, maps, sounds, links to specific pages on other domains) on the website that said user is visiting. This cookie policy has the specific purpose of illustrating the types and procedures of use, as well as to to provide guidance on the actions to refuse or delete cookies present on the website, where desired. Technical cookies are those used for the sole purpose of “carrying out the transmission of a communication over an electronic communications network, or as strictly necessary in order to provide an information society service explicitly requested by the subscriber or user” (see article 122(1) of the privacy code). These are not used for further purposes and can be used for divided into browsing or session cookies, which guarantee the normal browsing and use of the website (making it possible, for example, to make a purchase or authenticate oneself to access restricted areas); analytics cookies, similar to technical cookies when used directly by the website operator to collect information, in aggregate form, on the number of users and how they visit the website; functionality cookies, which allow the user to browse according to a set of selected criteria (for example, language or products selected for purchase) in order to improve the service rendered to them. Profiling cookies are designed to create user profiles and are used in order to send advertising messages in line with the preferences he/she shows when browsing the internet. No first party profiling cookies are in use. In particular, while browsing our website, these cookies are used to show you products you might like or similar ones to those you have already looked at, based on your browsing history. The use of these cookies does not normally entail the processing of personal data, but can enable connections to your computer or other devices and tracking of saved data: the cookies connect to the browser installed on your computer or on other devices used while browsing our website. PASTPRESENTFURTHER uses the following third-party cookies:</p>
    </section>

    <section class="legal-section">
      <h2>HOW TO DISABLE COOKIES</h2>
      <p>browsers are configured to accept cookies. However, most browsers allow you to control and also disable cookies through your browser settings. Please note, however, that disabling browsing or functionality cookies may cause the website to malfunction and/or limit the service we offer.</p>
    </section>

    <section class="legal-section">
      <h2>PERSONAL INFORMATION</h2>
      <p>Personal information provided by the user can be changed and editing by the user in the account section of the site after logging in. We would be happy to provide users with information we have about them via email at contact.</p>
    </section>

    <p class="legal-version">Version of the Terms and Conditions<br>The latest update of the Terms & Conditions was made in January, 2026.</p>
  </main>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
</body>
</html>

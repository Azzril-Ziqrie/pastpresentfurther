<?php
/* Shared admin chrome: grouped sidebar + glass top bar.
   Set before including:  $adminTitle (string, page title)
   Active menu item is detected from the current script name. */
$adminTitle  = $adminTitle ?? 'Admin';
$adminScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminLogo   = '../assets/images/brand/logo-admin.png';

$adminIcons = [
  'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
  'package' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
  'tag'     => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
  'ruler'   => '<path d="M3 17L17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/>',
  'percent' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
  'sort'    => '<path d="M3 6h18M3 12h12M3 18h6"/>',
  'cart'    => '<path d="M6 6h15l-2 9H8z"/><path d="M6 6L5 3H2"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>',
  'truck'   => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
  'image'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 9"/>',
  'book'    => '<path d="M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4z"/><path d="M20 4h-6v14h6z"/>',
  'file'    => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
  'lock'    => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
  'external'=> '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
];
$adminMenu = [
  'Overview' => [
    ['dashboard.php',            'Dashboard',          'grid'],
    ['dashboard.php#security',   'Security',           'lock'],
  ],
  'Catalog' => [
    ['dashboard-product.php',    'Products',           'package'],
    ['dashboard-categories.php', 'Categories',         'tag'],
    ['dashboard-size.php',       'Sizes & stock',      'ruler'],
    ['set-diskon.php',           'Discounts',          'percent'],
    ['admin_products.php',       'Product order',      'sort'],
  ],
  'Sales' => [
    ['dashboard-orders.php',     'Orders',             'cart'],
    ['send-resi.php',            'Send resi',          'truck'],
  ],
  'Storefront content' => [
    ['dashboard-hero.php',       'Hero slides',        'image'],
    ['lookbook.php',             'Lookbook',           'book'],
    ['dashboard-blogs.php',      'Research / blog',    'file'],
    ['dashboard-pages.php',      'Page photos',        'image'],
  ],
];
// order-detail belongs to Orders
$adminActive = $adminScript === 'order-detail.php' ? 'dashboard-orders.php' : $adminScript;
$svg = static fn(string $k) => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($adminIcons[$k] ?? '') . '</svg>';
?>
<input type="checkbox" id="admNavToggle" class="adm-nav-toggle" hidden>
<aside class="adm-side" aria-label="Admin navigation">
  <a href="dashboard.php" class="adm-brand" aria-label="Dashboard">
    <img src="<?= $adminLogo ?>" alt="">
    <span>pastpresentfurther<small>Admin</small></span>
  </a>
  <nav class="adm-nav">
    <?php foreach ($adminMenu as $group => $items): ?>
      <div class="adm-group">
        <div class="adm-group__title"><?= htmlspecialchars($group) ?></div>
        <?php foreach ($items as [$href, $label, $icon]): ?>
          <a href="<?= $href ?>" class="adm-link<?= $adminActive === $href ? ' is-active' : '' ?>"<?= $adminActive === $href ? ' aria-current="page"' : '' ?>>
            <?= $svg($icon) ?><span><?= htmlspecialchars($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <a href="../index.php" class="adm-link adm-store" target="_blank" rel="noopener">
    <?= $svg('external') ?><span>View storefront</span>
  </a>
</aside>
<label for="admNavToggle" class="adm-scrim" aria-hidden="true"></label>

<header class="adm-top">
  <label for="admNavToggle" class="adm-burger" aria-label="Menu"><i></i><i></i><i></i></label>
  <h1 class="adm-top__title"><?= htmlspecialchars($adminTitle) ?></h1>
  <a href="../index.php" class="adm-top__link" target="_blank" rel="noopener">Storefront ↗</a>
  <a href="?admin_logout=1" class="adm-top__link" style="margin-left:6px;color:var(--adm-mute)">Sign out</a>
</header>

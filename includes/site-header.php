<?php
/* Shared header: overlay, menu, cart, account + currency popups, navbar.
   Expects: $brandName, $brandLogo, $categories, $currentCurrency (optional: $search, $category). */
$search   = $search   ?? '';
$category = $category ?? '';
$currencyQs = ($search !== '' ? '&search=' . urlencode($search) : '') . ($category !== '' ? '&category=' . urlencode($category) : '') . ($currencyExtra ?? '');
$isLoggedIn = isset($_SESSION['user']['id']);
$currencyLabel = $currentCurrency === 'USD' ? '$ USD' : 'RP IDR';
?>
<div class="overlay" id="overlay"></div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<aside class="menu-panel" id="menuPanel" aria-label="Main menu">
  <div class="menu-body">
    <form class="menu-search-form" action="index.php" method="GET" role="search">
      <?php if ($category !== ''): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
      <?php endif; ?>
      <input type="text" name="search" placeholder="Type to search..." value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
      <button type="submit" aria-label="Search">&#8594;</button>
    </form>
    <nav class="menu-nav-primary">
      <div class="menu-dropdown" id="menuShop">
        <button type="button" class="menu-dropdown__toggle" aria-expanded="false" aria-controls="menuShopList">Shop</button>
        <div class="menu-dropdown__list" id="menuShopList">
          <div>
            <a href="shop.php">All</a>
            <?php foreach ($categories as $cat): ?>
              <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <a href="lookbook.php">Lookbook</a>
      <a href="research.php">Further Research</a>
      <button type="button" id="openCurrencyBtnMobile" aria-haspopup="dialog">Currency</button>
      <a href="about.php">About</a>
      <?php if (!$isLoggedIn): ?>
      <button type="button" id="openAccountBtnMobile" aria-label="Account">Account</button>
      <?php else: ?>
      <a href="logout.php">Logout</a>
      <?php endif; ?>
    </nav>
  </div>
</aside>

<aside class="cart-panel" id="cartPanel" aria-label="Shopping cart">
  <div class="cart-head">
    <div class="cart-title">Cart (<span id="cartCount">0</span>)</div>
    <button class="cart-close" id="closeCart" aria-label="Close cart">×</button>
  </div>
  <div class="cart-body" id="cartBody">
    <div class="cart-empty">
      <span>Your cart is empty</span>
    </div>
  </div>
  <div class="cart-foot" id="cartFoot" style="display:none">
    <div class="cart-total">
      <span>Total</span>
      <strong id="cartTotal">RP 0</strong>
    </div>
    <button class="cart-checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
  </div>
</aside>

<?php if (!$isLoggedIn): ?>
<div id="accountPopup" class="account-popup" role="dialog" aria-modal="true" aria-labelledby="accountPopupTitle">
  <div class="account-popup__backdrop" id="accountBackdrop"></div>
  <div class="account-popup__dialog">
    <button type="button" class="popup-close" id="closeAccountBtn" aria-label="Close">×</button>
    <div class="account-popup__eyebrow"><?= htmlspecialchars($brandName) ?></div>
    <h2 class="account-popup__title" id="accountPopupTitle">My Account</h2>
    <div class="account-tabs">
      <button class="account-tab active" data-tab="login" aria-selected="true">Sign In</button>
      <button class="account-tab" data-tab="register" aria-selected="false">Register</button>
    </div>
    <div id="tabLogin">
      <div id="loginMsg" class="account-msg" style="display:none"></div>
      <div class="account-form">
        <input type="email" id="loginEmail" placeholder="Email" autocomplete="email">
        <input type="password" id="loginPassword" placeholder="Password" autocomplete="current-password">
        <button class="account-submit" id="loginBtn">Sign In</button>
      </div>
    </div>
    <div id="tabRegister" style="display:none">
      <div id="registerMsg" class="account-msg" style="display:none"></div>
      <div class="account-form">
        <input type="text" id="regName" placeholder="Full Name" autocomplete="name">
        <input type="email" id="regEmail" placeholder="Email" autocomplete="email">
        <input type="password" id="regPassword" placeholder="Password (min. 6 characters)" autocomplete="new-password">
        <input type="password" id="regPasswordConfirm" placeholder="Confirm Password" autocomplete="new-password">
        <button class="account-submit" id="registerBtn">Create Account</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="currencyPopup" class="currency-popup" role="dialog" aria-modal="true" aria-label="Currency">
  <div class="currency-popup__backdrop" id="currencyBackdrop"></div>
  <div class="currency-popup__panel">
    <p class="currency-popup__label">Currency</p>
    <div class="currency-select" id="currencySelect">
      <button type="button" class="currency-select__head" id="currencyToggle" aria-haspopup="listbox" aria-expanded="false">
        <span class="currency-select__country"><?= $currentCurrency === 'USD' ? 'Worldwide' : 'Indonesia' ?></span>
        <strong><?= $currencyLabel ?></strong>
        <i class="currency-select__chev" aria-hidden="true"></i>
      </button>
      <ul class="currency-select__list" role="listbox">
        <li><a href="?currency=IDR<?= $currencyQs ?>" role="option"<?= $currentCurrency === 'IDR' ? ' aria-selected="true"' : '' ?>><span>Indonesia</span><strong>RP IDR</strong></a></li>
        <li><a href="?currency=USD<?= $currencyQs ?>" role="option"<?= $currentCurrency === 'USD' ? ' aria-selected="true"' : '' ?>><span>Worldwide</span><strong>$ USD</strong></a></li>
      </ul>
    </div>
  </div>
</div>

<header class="navbar">
  <div class="nav-left">
    <button class="menu-icon-wrap" id="menuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="menuPanel">
      <span class="menu-icon" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="menu-label">Menu</span>
    </button>
    <div class="nav-dropdown" id="navShop">
      <button type="button" class="nav-link nav-dropdown__toggle" aria-haspopup="true" aria-expanded="false">Shop</button>
      <div class="nav-dropdown__menu">
        <a href="shop.php">All</a>
        <?php foreach ($categories as $cat): ?>
          <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <a class="nav-link" href="lookbook.php">Lookbook</a>
    <a class="nav-link" href="research.php">Further Research</a>
    <a class="nav-link" href="about.php">About</a>
  </div>

  <div class="nav-center">
    <a href="index.php" class="brand-mark" aria-label="<?= htmlspecialchars($brandName) ?>">
      <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
    </a>
  </div>

  <div class="nav-right">
    <form class="nav-search<?= $search !== '' ? ' open' : '' ?>" id="navSearch" action="index.php" method="GET" role="search">
      <?php if ($category !== ''): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
      <?php endif; ?>
      <input type="text" id="navSearchInput" name="search" placeholder="Search" value="<?= htmlspecialchars($search) ?>" aria-label="Search products" autocomplete="off">
      <button type="button" class="nav-link" id="searchToggle">Search</button>
    </form>
    <?php if (!$isLoggedIn): ?>
    <button class="nav-link" id="openAccountBtn" aria-label="Account">Account</button>
    <?php else: ?>
    <a class="nav-link" href="logout.php">Logout</a>
    <?php endif; ?>
    <button class="nav-link" id="openCurrencyBtn" aria-haspopup="dialog" aria-label="Currency">Currency</button>
    <button class="nav-link" id="openCartBtn" aria-label="Cart">Cart <span class="nav-count" id="navCartCount">0</span></button>
  </div>
</header>


<script>window.PPF_CURRENCY = <?= json_encode($currentCurrency) ?>;</script>

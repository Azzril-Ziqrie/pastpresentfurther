/* Shared site chrome: menu, cart, account, currency, search. Used by index, lookbook, research. */
(function () {
    'use strict';

    const CURRENT_CURRENCY = window.PPF_CURRENCY || 'IDR';

    // ── Helper aman: pasang addEventListener hanya jika elemen ada ──
    // Ini mencegah error "Cannot read properties of null (reading 'addEventListener')"
    // jika suatu saat ada id HTML yang berubah/hilang.
    function on(id, event, handler) {
      const el = document.getElementById(id);
      if (el) el.addEventListener(event, handler);
      return el;
    }
    function onAll(selector, event, handler) {
      document.querySelectorAll(selector).forEach(el => el.addEventListener(event, handler));
    }

    const body         = document.body;
    const overlay      = document.getElementById('overlay');
    const menuPanel    = document.getElementById('menuPanel');
    const cartPanel    = document.getElementById('cartPanel');
    const accountPopup = document.getElementById('accountPopup');
    const currencyPopup = document.getElementById('currencyPopup');

    let cartItems = JSON.parse(localStorage.getItem('ppf_cart') || '[]');

    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      if (!container) return;
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2600);
    }

    function setOverlay(active) {
      if (overlay) overlay.classList.toggle('active', active);
      body.classList.toggle('lock-scroll', active);
    }

    function anyPanelOpen() {
      return (menuPanel && menuPanel.classList.contains('active')) ||
             (cartPanel && cartPanel.classList.contains('active')) ||
             (accountPopup && accountPopup.classList.contains('active')) ||
             (currencyPopup && currencyPopup.classList.contains('active'));
    }

    function closeAll() {
      if (menuPanel) menuPanel.classList.remove('active');
      syncMenuToggle();
      if (cartPanel) cartPanel.classList.remove('active');
      if (accountPopup) accountPopup.classList.remove('active');
      if (currencyPopup) { currencyPopup.classList.remove('active'); const cs = document.getElementById('currencySelect'); if (cs) cs.classList.remove('open'); }
    }

    function syncMenuToggle() {
      const btn = document.getElementById('menuToggle');
      if (!btn) return;
      const open = !!(menuPanel && menuPanel.classList.contains('active'));
      btn.setAttribute('aria-expanded', open);
      btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      const label = btn.querySelector('.menu-label');
      if (label) label.textContent = open ? 'Close' : 'Menu';
    }
    function openMenu() {
      closeAll();
      if (menuPanel) menuPanel.classList.add('active');
      body.classList.add('lock-scroll');
      syncMenuToggle();
    }
    function toggleMenu() {
      if (menuPanel && menuPanel.classList.contains('active')) { closeAll(); if (!anyPanelOpen()) setOverlay(false); }
      else openMenu();
    }
    function openCart()  { closeAll(); if (cartPanel) cartPanel.classList.add('active');  setOverlay(true); }
    function openAccount() { closeAll(); if (accountPopup) accountPopup.classList.add('active'); setOverlay(true); }

    /* ── SEARCH TOGGLE ─────────────────────────────────── */
    (function () {
      const form  = document.getElementById('navSearch');
      const input = document.getElementById('navSearchInput');
      const btn   = document.getElementById('searchToggle');
      if (!form || !input || !btn) return;
      btn.addEventListener('click', () => {
        if (!form.classList.contains('open')) { form.classList.add('open'); input.focus(); return; }
        if (input.value.trim() !== '') form.submit(); else form.classList.remove('open');
      });
      input.addEventListener('blur', () => { if (input.value.trim() === '') form.classList.remove('open'); });
    })();

    /* ── SHOP DROPDOWN (navbar + drawer) ───────────────── */
    (function () {
      const nav = document.getElementById('navShop');
      const navBtn = nav && nav.querySelector('.nav-dropdown__toggle');
      function setNav(open) { if (!nav) return; nav.classList.toggle('open', open); navBtn.setAttribute('aria-expanded', open); }
      if (navBtn) navBtn.addEventListener('click', e => { e.stopPropagation(); setNav(!nav.classList.contains('open')); });
      document.addEventListener('click', e => { if (nav && !nav.contains(e.target)) setNav(false); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') setNav(false); });

      const acc = document.getElementById('menuShop');
      const accBtn = acc && acc.querySelector('.menu-dropdown__toggle');
      if (accBtn) accBtn.addEventListener('click', () => {
        const open = acc.classList.toggle('open');
        accBtn.setAttribute('aria-expanded', open);
      });
    })();

    on('menuToggle', 'click', toggleMenu);
    on('openCartBtn', 'click', openCart);
    on('closeCart', 'click', () => { if (cartPanel) cartPanel.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    on('openAccountBtn', 'click', openAccount);
    function closeCurrency() { closeAll(); if (!anyPanelOpen()) setOverlay(false); }
    function openCurrency() { closeAll(); if (currencyPopup) currencyPopup.classList.add('active'); setOverlay(true); }
    on('openCurrencyBtn', 'click', openCurrency);
    on('openCurrencyBtnMobile', 'click', openCurrency);
    on('currencyToggle', 'click', () => {
      const box = document.getElementById('currencySelect');
      const open = box.classList.toggle('open');
      document.getElementById('currencyToggle').setAttribute('aria-expanded', open);
    });
    on('currencyBackdrop', 'click', closeCurrency);
    on('openAccountBtnMobile', 'click', openAccount);

    on('closeAccountBtn', 'click', () => { if (accountPopup) accountPopup.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    on('accountBackdrop', 'click', () => { if (accountPopup) accountPopup.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });

    if (overlay) overlay.addEventListener('click', () => { closeAll(); setOverlay(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAll(); setOverlay(false); } });

    onAll('.account-tab', 'click', function () {
      document.querySelectorAll('.account-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
      this.classList.add('active'); this.setAttribute('aria-selected','true');
      const target = this.dataset.tab;
      const tabLogin    = document.getElementById('tabLogin');
      const tabRegister = document.getElementById('tabRegister');
      if (tabLogin)    tabLogin.style.display    = target === 'login'    ? '' : 'none';
      if (tabRegister) tabRegister.style.display = target === 'register' ? '' : 'none';
    });

    on('loginBtn', 'click', function () { submitAccount('login'); });
    on('registerBtn', 'click', function () { submitAccount('register'); });

    function setAccountMsg(target, kind, text) {
      const el = document.getElementById(target);
      if (!el) return;
      el.className = 'account-msg ' + kind;
      el.textContent = text;
      el.style.display = 'block';
    }

    function setAccountSubmitDisabled(disabled) {
      const loginBtn    = document.getElementById('loginBtn');
      const registerBtn = document.getElementById('registerBtn');
      if (loginBtn) {
        loginBtn.disabled = disabled;
        loginBtn.textContent = disabled ? 'Signing In...' : 'Sign In';
      }
      if (registerBtn) {
        registerBtn.disabled = disabled;
        registerBtn.textContent = disabled ? 'Creating Account...' : 'Create Account';
      }
    }

    function validateEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function submitAccount(action) {
      const isLogin = action === 'login';
      const msgTarget = isLogin ? 'loginMsg' : 'registerMsg';

      const regNameEl = document.getElementById('regName');
      const name  = regNameEl ? regNameEl.value.trim() : '';

      const emailEl = document.getElementById(isLogin ? 'loginEmail' : 'regEmail');
      const email = emailEl ? emailEl.value.trim() : '';

      const passEl = document.getElementById(isLogin ? 'loginPassword' : 'regPassword');
      const pass  = passEl ? passEl.value : '';

      const confirmEl = document.getElementById('regPasswordConfirm');
      const confirm = confirmEl ? confirmEl.value : '';

      if (isLogin) {
        if (!email || !pass) return setAccountMsg(msgTarget, 'error', 'Please fill in all fields.');
        if (!validateEmail(email)) return setAccountMsg(msgTarget, 'error', 'Please enter a valid email address.');
      } else {
        if (!name || !email || !pass || !confirm) return setAccountMsg(msgTarget, 'error', 'Please complete all required fields.');
        if (!validateEmail(email)) return setAccountMsg(msgTarget, 'error', 'Please enter a valid email address.');
        if (pass.length < 6) return setAccountMsg(msgTarget, 'error', 'Password must be at least 6 characters.');
        if (pass !== confirm) return setAccountMsg(msgTarget, 'error', 'Password confirmation does not match.');
      }

      setAccountMsg(msgTarget, 'success', isLogin ? 'Signing in...' : 'Creating account...');
      setAccountSubmitDisabled(true);

      const bodyParams = new URLSearchParams();
      bodyParams.set('action', action);
      bodyParams.set('email', email);
      bodyParams.set('password', pass);
      if (!isLogin) {
        bodyParams.set('full_name', name);
        bodyParams.set('confirm_password', confirm);
      }

      fetch('auth_api.php', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: bodyParams.toString(),
      })
      .then(r => r.json().catch(() => ({ ok: false, message: 'Unexpected server response.' })).then(data => ({ status: r.status, data })))
      .then(({ status, data }) => {
        if (!data || data.ok !== true) {
          setAccountMsg(msgTarget, 'error', (data && data.message) ? data.message : 'Something went wrong.');
          setAccountSubmitDisabled(false);
          return;
        }
        const redirect = (data.redirect && typeof data.redirect === 'string') ? data.redirect : 'index.php';
        setAccountMsg(msgTarget, 'success', isLogin ? 'Signed in. Refreshing...' : 'Account created. Refreshing...');
        setTimeout(() => { window.location.href = redirect; }, 450);
      })
      .catch(err => {
        console.error('[auth]', err);
        setAccountMsg(msgTarget, 'error', 'Network error. Please try again.');
        setAccountSubmitDisabled(false);
      });
    }

    /* ── CART ──────────────────────────────────────────── */
    function saveCart() { localStorage.setItem('ppf_cart', JSON.stringify(cartItems)); }

    // Fungsi Format Currency JS disesuaikan
    function formatCurrencyJS(amount) {
      if (CURRENT_CURRENCY === 'USD') {
        return '$' + parseFloat(amount).toFixed(2);
      }
      return 'RP ' + Math.round(amount).toLocaleString('id-ID');
    }

    function renderCart() {
      const cbody  = document.getElementById('cartBody');
      const foot   = document.getElementById('cartFoot');
      const count  = cartItems.reduce((s, i) => s + i.qty, 0);
      const total  = cartItems.reduce((s, i) => s + i.price * i.qty, 0);

      const cartCountEl    = document.getElementById('cartCount');
      const navCartCountEl = document.getElementById('navCartCount');
      if (cartCountEl)    cartCountEl.textContent    = count;
      if (navCartCountEl) navCartCountEl.textContent = count;

      if (!cbody || !foot) return;

      if (cartItems.length === 0) {
        cbody.innerHTML = '<div class="cart-empty"><span>Your cart is empty</span></div>';
        foot.style.display = 'none';
        return;
      }

      foot.style.display = '';
      const cartTotalEl = document.getElementById('cartTotal');
      if (cartTotalEl) cartTotalEl.textContent = formatCurrencyJS(total);

      cbody.innerHTML = cartItems.map((item, idx) => `
        <div class="cart-item">
          <img class="cart-item-img" src="${item.thumb}" alt="${item.name}">
          <div>
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-item-price">${formatCurrencyJS(item.price)}</div>
            <div class="cart-item-qty">
              <button aria-label="Decrease" data-action="dec" data-idx="${idx}">−</button>
              <span>${item.qty}</span>
              <button aria-label="Increase" data-action="inc" data-idx="${idx}">+</button>
            </div>
          </div>
          <button class="cart-item-remove" aria-label="Remove" data-action="remove" data-idx="${idx}">×</button>
        </div>
      `).join('');

      cbody.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx    = parseInt(btn.dataset.idx, 10);
          const action = btn.dataset.action;
          if (action === 'inc') {
            const stockLimit = parseInt(cartItems[idx].stock || '0', 10) || 0;
            if (stockLimit > 0 && cartItems[idx].qty >= stockLimit) {
              showToast('Out Of Stock');
              return;
            }
            cartItems[idx].qty++;
          }
          else if (action === 'dec') { cartItems[idx].qty--; if (cartItems[idx].qty <= 0) cartItems.splice(idx, 1); }
          else if (action === 'remove') { cartItems.splice(idx, 1); }
          saveCart(); renderCart();
        });
      });
    }

    on('checkoutBtn', 'click', async () => {
      const btn = document.getElementById('checkoutBtn');
      const label = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Preparing Checkout...'; }
      try {
        const fd = new FormData();
        fd.append('cart_data', JSON.stringify(cartItems));
        await fetch('sync-cart.php', { method: 'POST', body: fd });
        window.location.href = 'checkout.php';
      } catch (err) {
        showToast('Failed to prepare checkout.');
        if (btn) { btn.disabled = false; btn.textContent = label; }
      }
    });

    renderCart();

    // Dipakai halaman lain (mis. product.php) untuk menambah item ke cart.
    window.PPF = {
      openCart: openCart,
      openCurrency: openCurrency,
      showToast: showToast,
      renderCart: renderCart,
      saveCart: saveCart,
      getCart: () => cartItems
    };

})();

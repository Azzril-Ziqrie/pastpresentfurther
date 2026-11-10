<?php
/* ─────────────────────────────────────────────────────────────
   Page loader — include right after <body> (root pages only).
   Look: white field and a single hairline progress sweep — nothing else.
   Behaviour: never blocks longer than needed (hides on load, 3s failsafe),
   appears on navigation only if the next page is slow (>150ms) so fast
   pages never flash it, and honours prefers-reduced-motion.
───────────────────────────────────────────────────────────── */
include __DIR__ . '/theme-boot.php';
?>
<style>
  #global-loader{
    --ld-ease:cubic-bezier(.32,.72,0,1);
    position:fixed;inset:0;z-index:99999;
    display:flex;align-items:center;justify-content:center;
    background:var(--bg,#fff);color:var(--ink,#111);
    font-family:'JetBrains Mono',ui-monospace,Menlo,Consolas,monospace;
    opacity:1;visibility:visible;
    -webkit-backdrop-filter:blur(0);backdrop-filter:blur(0);
    transition:opacity .55s var(--ld-ease),visibility 0s linear 0s;
  }
  /* leaving: fade out while the mark lifts away */
  #global-loader.hidden{opacity:0;visibility:hidden;pointer-events:none;transition:opacity .55s var(--ld-ease),visibility 0s linear .55s}
  /* navigating away: start transparent, fade in only when the wait is real */
  #global-loader.is-nav{background:var(--loader-nav,rgba(255,255,255,.82));-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
  #global-loader.is-nav.hidden{transition:none}

  .ld-stack{display:flex;flex-direction:column;align-items:center;transform:translateY(0) scale(1);transition:transform .55s var(--ld-ease),opacity .4s ease}
  #global-loader.hidden .ld-stack{opacity:0}

  /* hairline track + sweeping highlight */
  .ld-track{position:relative;width:96px;height:1px;background:#e3e3e3;overflow:hidden;animation:ldIn .9s .2s var(--ld-ease) both}
  .ld-track::after{content:"";position:absolute;inset:0 auto 0 0;width:38%;background:var(--ink,#111);animation:ldSweep 1.15s var(--ld-ease) infinite}

  @keyframes ldIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  @keyframes ldSweep{0%{transform:translateX(-100%)}100%{transform:translateX(265%)}}

  @media(max-width:760px){.ld-track{width:72px}}
  @media(prefers-reduced-motion:reduce){
    .ld-track{animation:none}
    .ld-track::after{animation:none;width:100%;opacity:.35}
    #global-loader,#global-loader.hidden{transition:opacity .2s linear}
    #global-loader.hidden .ld-stack{transform:none}
  }
  @media(prefers-reduced-transparency:reduce){#global-loader.is-nav{background:var(--bg,#fff);-webkit-backdrop-filter:none;backdrop-filter:none}}
</style>

<div id="global-loader" role="status" aria-live="polite" aria-label="Loading">
  <div class="ld-stack">
    <span class="ld-track" aria-hidden="true"></span>
  </div>
</div>
<noscript><style>#global-loader{display:none}</style></noscript>

<script>
(function () {
  var loader = document.getElementById('global-loader');
  if (!loader) return;

  var MIN_SHOW = 450;           // ms – long enough to read as intentional, short enough to feel instant
  var FAILSAFE = 3000;          // ms – never trap the visitor behind the loader
  var start    = Date.now();
  var navTimer = null;

  function hide() {
    clearTimeout(navTimer);
    loader.classList.add('hidden');
  }
  function hideAfterMin() {
    setTimeout(hide, Math.max(0, MIN_SHOW - (Date.now() - start)));
  }

  // 1) first paint → hide once the page is ready (or after the failsafe)
  if (document.readyState === 'complete') hideAfterMin();
  else window.addEventListener('load', hideAfterMin);
  setTimeout(hide, FAILSAFE);

  // 2) leaving the page → show the loader only if the next page is slow
  function showForNavigation() {
    clearTimeout(navTimer);
    navTimer = setTimeout(function () {
      loader.classList.add('is-nav');
      loader.classList.remove('hidden');
      start = Date.now();
    }, 150);
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var href = a.getAttribute('href') || '';
    if (a.target === '_blank' || a.hasAttribute('download') ||
        !href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
    try { if (new URL(a.href, location.href).origin !== location.origin) return; } catch (_) { return; }
    // let other handlers cancel the navigation first (e.g. JS-driven links)
    setTimeout(function () { if (!e.defaultPrevented) showForNavigation(); }, 0);
  });

  document.addEventListener('submit', function (e) {
    setTimeout(function () { if (!e.defaultPrevented) showForNavigation(); }, 0);
  });

  // 3) back/forward cache restore → never leave a stale loader on screen
  window.addEventListener('pageshow', function (e) { if (e.persisted) hide(); });
})();
</script>

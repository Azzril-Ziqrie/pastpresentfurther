/* Bar pengumuman: animasi bergantian / marquee + tombol ✕.
   Markup: includes/announcement-bar.php   Gaya: assets/css/announcement.css

   Mode (data-mode): slide | fade | blink | marquee | none
   Opsi: data-interval (detik antar pengumuman), data-speed (1-10, kecepatan marquee).
   API: window.PPFAnnouncement.mount(bar) -> { destroy() }  (dipakai juga oleh preview dashboard) */
(function () {
  'use strict';

  const STORAGE_KEY        = 'ppf_announce_closed';
  const CLOSE_ANIMATION_MS = 300;
  const LEAVE_MS           = 450;
  const RESIZE_DEBOUNCE_MS = 200;
  const MARQUEE_BASE_PX_S  = 24;   // kecepatan marquee: BASE + STEP * speed (px per detik)
  const MARQUEE_STEP_PX_S  = 8;
  const MARQUEE_MAX_COPIES = 8;

  function prefersReducedMotion() {
    return Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function readOptions(bar) {
    return {
      mode: bar.dataset.mode || 'slide',
      intervalMs: Math.max(2, Number(bar.dataset.interval) || 4) * 1000,
      speed: Math.max(1, Math.min(10, Number(bar.dataset.speed) || 5)),
    };
  }

  /* ── bergantian: slide / fade / blink ───────────────── */
  function startCycle(items, { mode, intervalMs }) {
    let index = 0;
    let timer = null;

    function enter(item) {
      item.classList.remove('is-leaving');
      item.classList.add('is-active', 'is-entering');
    }

    function leave(item) {
      item.classList.remove('is-active', 'is-entering');
      item.classList.add('is-leaving');
      setTimeout(() => item.classList.remove('is-leaving'), LEAVE_MS);
    }

    function replay(item) {
      item.classList.remove('is-entering');
      void item.offsetWidth; // paksa reflow agar animasi mulai dari awal
      item.classList.add('is-entering');
    }

    function tick() {
      if (items.length > 1) {
        const next = (index + 1) % items.length;
        leave(items[index]);
        index = next;
        enter(items[index]);
      } else if (mode === 'blink') {
        replay(items[0]);
      }
    }

    function start() { if (!timer) timer = setInterval(tick, intervalMs); }
    function stop()  { clearInterval(timer); timer = null; }
    function onVisibility() { document.hidden ? stop() : start(); }

    enter(items[0]);
    start();
    document.addEventListener('visibilitychange', onVisibility);

    return function destroy() {
      stop();
      document.removeEventListener('visibilitychange', onVisibility);
      items.forEach((item, i) => {
        item.classList.remove('is-entering', 'is-leaving');
        item.classList.toggle('is-active', i === 0);
      });
    };
  }

  /* ── marquee: berjalan terus ke kanan ───────────────── */
  function startMarquee(bar, items, { speed }) {
    const stage = bar.querySelector('.announce__stage');
    const track = document.createElement('div');
    track.className = 'announce__track';

    const buildHalf = copies => {
      const half = document.createElement('div');
      half.className = 'announce__half';
      for (let i = 0; i < copies; i++) {
        items.forEach(item => half.appendChild(item.cloneNode(true)));
      }
      return half;
    };

    bar.classList.add('is-marquee');
    stage.appendChild(track);

    // Ulang isi sampai satu paruh lebih lebar dari bar, supaya tidak ada celah kosong.
    let half = buildHalf(1);
    track.appendChild(half);
    let copies = 1;
    while (half.scrollWidth < stage.clientWidth && copies < MARQUEE_MAX_COPIES) {
      copies += 1;
      half.remove();
      half = buildHalf(copies);
      track.appendChild(half);
    }
    const mirror = half.cloneNode(true);
    mirror.setAttribute('aria-hidden', 'true');
    track.appendChild(mirror);

    const pxPerSecond = MARQUEE_BASE_PX_S + MARQUEE_STEP_PX_S * speed;
    track.style.setProperty('--ann-dur', (half.scrollWidth / pxPerSecond) + 's');

    return function destroy() {
      track.remove();
      bar.classList.remove('is-marquee');
    };
  }

  /* ── pasang ke sebuah bar ───────────────────────────── */
  function mount(bar) {
    if (bar._ppfAnnounce) bar._ppfAnnounce.destroy();

    const items = Array.from(bar.querySelectorAll('.announce__stage > .announce__item'));
    const options = readOptions(bar);
    let stopAnimation = null;

    if (items.length > 0 && options.mode !== 'none') {
      const reduced = prefersReducedMotion();
      if (options.mode === 'marquee' && !reduced) {
        stopAnimation = startMarquee(bar, items, options);
      } else {
        // "kurangi gerakan": marquee diganti pergantian pudar biasa
        const mode = options.mode === 'marquee' ? 'fade' : options.mode;
        bar.dataset.effectiveMode = mode;
        stopAnimation = startCycle(items, { ...options, mode });
      }
    }

    const controller = {
      destroy() {
        if (stopAnimation) stopAnimation();
        window.removeEventListener('resize', onResize);
        bar._ppfAnnounce = null;
      },
    };

    let resizeTimer = null;
    function onResize() {
      if (options.mode !== 'marquee') return;
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => mount(bar), RESIZE_DEBOUNCE_MS);
    }
    window.addEventListener('resize', onResize);

    bar._ppfAnnounce = controller;
    return controller;
  }

  /* ── tombol ✕ ───────────────────────────────────────── */
  function bindClose(bar) {
    const button = bar.querySelector('#announceClose');
    if (!button) return;

    button.addEventListener('click', () => {
      try { localStorage.setItem(STORAGE_KEY, bar.dataset.version); } catch (e) { /* mode privat */ }
      bar.classList.add('is-closing');
      setTimeout(() => {
        if (bar._ppfAnnounce) bar._ppfAnnounce.destroy();
        bar.remove();
      }, CLOSE_ANIMATION_MS);
    });
  }

  function init() {
    document.querySelectorAll('.announce[data-announce]').forEach(bar => {
      mount(bar);
      bindClose(bar);
    });
  }

  window.PPFAnnouncement = { mount };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

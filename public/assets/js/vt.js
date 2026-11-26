/* Cashflow HME — transisi antar halaman.
   Chrome/Edge/Safari baru: View Transitions lintas-dokumen (@view-transition di app.css).
   - Halaman baru "terbuka" membesar dari titik yang diklik (lingkaran) + zoom/blur halus.
   - Arah gerak mengikuti urutan menu (turun/naik) atau masuk/keluar halaman detail.
   - Kartu masuk berurutan (kelas .vt-in), bilah progres tampil saat menunggu halaman berikutnya.
   Nama transisi hanya dipasang SELAMA transisi (pageswap/pagereveal) agar tidak mengubah tata letak
   elemen fixed (modal, tooltip). Peramban lain (mis. Firefox): kelas .no-vt -> animasi masuk via CSS.
   Dimuat di <head> agar pagereveal tertangkap. */
(function () {
  'use strict';
  var root = document.documentElement;
  var KEY_PT = 'vtPt', KEY_DIR = 'vtDir';
  var NAMES = [['.sidebar', 'vt-side'], ['.bottomnav', 'vt-bnav'], ['.page-head', 'vt-head'], ['.content', 'vt-page']];
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function store(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* abaikan */ } }
  function load(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function tag(on) {
    NAMES.forEach(function (n) {
      var el = document.querySelector(n[0]);
      if (el) el.style.viewTransitionName = on ? n[1] : '';
    });
  }
  function staggerOn() {
    if (reduce) return;
    root.classList.add('vt-in');
    setTimeout(function () { root.classList.remove('vt-in'); }, 1800);
  }

  // ---------- bilah progres + titik klik (semua peramban) ----------
  function centerOf(el) {
    var r = el.getBoundingClientRect();
    return Math.round(r.left + r.width / 2) + ',' + Math.round(r.top + r.height / 2);
  }
  function leaving() {
    if (reduce) return;
    root.classList.add('vt-leaving');
    setTimeout(function () { root.classList.remove('vt-leaving'); }, 10000); // mis. unduhan: halaman tidak berganti
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
    var u;
    try { u = new URL(a.href, location.href); } catch (err) { return; }
    if (u.origin !== location.origin || /\/export\b/.test(u.pathname)) return;
    if (u.pathname === location.pathname && u.search === location.search) return; // jangkar/halaman yang sama
    store(KEY_PT, centerOf(a));
    leaving();
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || e.defaultPrevented || (f.target && f.target !== '_self')) return;
    if (/\/export\b/.test(f.action || '')) return;
    var s = e.submitter || f.querySelector('[type=submit]');
    if (s) store(KEY_PT, centerOf(s));
    leaving();
  }, true);
  window.addEventListener('pageshow', function () { root.classList.remove('vt-leaving'); });

  // ---------- prefetch halaman tujuan (peramban Chromium) ----------
  // Saat kursor melayang ~200 ms di atas tautan, HTML halaman tujuan sudah diambil: klik terasa instan dan
  // transisi tidak menunggu jaringan/PHP. Hanya GET baca-saja; ekspor, API, impor, cadangan & keluar dikecualikan.
  function speculate() {
    try {
      if (!window.HTMLScriptElement || !HTMLScriptElement.supports || !HTMLScriptElement.supports('speculationrules')) return;
      var m = document.querySelector('meta[name="app-base"]');
      var base = ((m && m.content) || '').replace(/\/$/, '');
      var sk = document.createElement('script');
      sk.type = 'speculationrules';
      sk.textContent = JSON.stringify({
        prefetch: [{
          where: { and: [
            { href_matches: base + '/*' },
            { not: { href_matches: [base + '/export/*', base + '/api/*', base + '/import/*', base + '/settings/backup/*', base + '/logout', base + '/sw.js', base + '/manifest.webmanifest'] } },
            { not: { selector_matches: '[target=_blank], [download], [data-no-prefetch]' } }
          ] },
          eagerness: 'moderate'
        }]
      });
      document.head.appendChild(sk);
    } catch (e) { /* abaikan */ }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', speculate); else speculate();

  if (!('onpageswap' in window) || !('onpagereveal' in window)) {
    root.classList.add('no-vt');
    staggerOn();
    return;
  }

  // ---------- arah gerak ----------
  function navOrder() {
    var out = [];
    document.querySelectorAll('.sidebar .sb-link[href]').forEach(function (a) {
      try { out.push(new URL(a.href, location.href).pathname); } catch (e) { /* abaikan */ }
    });
    return out;
  }
  function idxOf(order, path) {
    var best = -1, len = -1;
    order.forEach(function (p, i) {
      var m = path === p || (p.length > 1 && path.indexOf(p + '/') === 0);
      if (m && p.length > len) { best = i; len = p.length; }
    });
    return best;
  }
  function direction(toUrl) {
    var to, from = location.pathname;
    try { to = new URL(toUrl, location.href).pathname; } catch (e) { return 'down'; }
    if (to === from) return 'in';
    var order = navOrder(), ea = order.indexOf(from), eb = order.indexOf(to);
    if (ea >= 0 && eb >= 0) return eb > ea ? 'down' : 'up';                                // antar menu utama (termasuk Dashboard)
    if (from.length > 1 && to.indexOf(from.replace(/\/$/, '') + '/') === 0) return 'in';   // ke halaman detail/anak
    if (to.length > 1 && from.indexOf(to.replace(/\/$/, '') + '/') === 0) return 'out';    // kembali ke induk
    var a = idxOf(order, from), b = idxOf(order, to);
    if (a >= 0 && b >= 0 && a !== b) return b > a ? 'down' : 'up';
    return 'in';
  }

  window.addEventListener('pageswap', function (e) {
    if (!e.viewTransition) return;
    var dest = e.activation && e.activation.entry && e.activation.entry.url;
    store(KEY_DIR, direction(dest || location.href));
    tag(true);
  });

  window.addEventListener('pagereveal', function (e) {
    if (!e.viewTransition) return;
    var dir = load(KEY_DIR) || 'down';
    // [translateY halaman, translateX judul, skala awal halaman]
    var vec = { down: [30, 24, .99], up: [-30, -24, .99], 'in': [14, 32, .965], out: [14, -32, 1.03] }[dir] || [26, 22, .99];
    var content = document.querySelector('.content');
    var pt = (load(KEY_PT) || '').split(',');
    var px = parseFloat(pt[0]), py = parseFloat(pt[1]);
    if (isNaN(px) || isNaN(py)) { px = 60; py = 140; }
    var r = content ? content.getBoundingClientRect() : { left: 0, top: 0, width: innerWidth, height: innerHeight };
    var x = px - r.left, y = py - r.top;
    var rad = Math.hypot(Math.max(x, r.width - x), Math.max(y, r.height - y));
    var s = root.style;
    s.setProperty('--vt-x', Math.round(x) + 'px');
    s.setProperty('--vt-y', Math.round(y) + 'px');
    s.setProperty('--vt-r', Math.ceil(rad) + 'px');
    s.setProperty('--vt-dy', vec[0] + 'px');
    s.setProperty('--vt-dx', vec[1] + 'px');
    s.setProperty('--vt-s', vec[2]);
    tag(true);
    staggerOn();
    var done = function () {
      tag(false);
      ['--vt-x', '--vt-y', '--vt-r', '--vt-dy', '--vt-dx', '--vt-s'].forEach(function (p) { s.removeProperty(p); });
    };
    e.viewTransition.finished.then(done, done);
  });
})();

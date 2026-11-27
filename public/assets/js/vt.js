/* Cashflow HME — transisi antar halaman (View Transitions lintas-dokumen, Chrome/Edge/Safari baru).
   Animasi DIPILIH menurut konteks klik (bukan satu efek untuk semua):
     down / up   pindah antar menu utama (geser vertikal berlapis, arah mengikuti urutan sidebar)
     in          masuk halaman detail/anak: zoom-masuk dari titik yang diklik
     out         kembali ke induk: zoom-keluar
     morph       klik kartu kecil -> kartu itu "memuai" menjadi kartu halaman tujuan (shared element)
     next / prev pindah halaman tabel / tab (geser horizontal sesuai arah)
     fade        filter / pencarian pada halaman yang sama (cross-fade cepat tanpa gerak)
     rise        simpan form (POST): halaman baru terangkat dengan sedikit pantulan
   Respons instan: begitu diklik, halaman lama langsung bergerak ke arah tujuan (kelas .vt-leaving +
   data-vt-go) selagi menunggu server, lalu snapshot-nya diteruskan mulus oleh transisi.
   Prefetch (Speculation Rules) mengambil halaman tujuan saat kursor melayang, jadi jeda hampir nol.
   Nama transisi hanya dipasang SELAMA transisi agar tidak mengubah tata letak elemen fixed.
   Peramban tanpa View Transitions (mis. Firefox): kelas .no-vt -> animasi masuk via CSS.
   Dimuat di <head> agar pagereveal tertangkap. */
(function () {
  'use strict';
  var root = document.documentElement;
  var KEY_PT = 'vtPt', KEY_KIND = 'vtKind', KEY_MORPH = 'vtMorph';
  var NAMES = [['.sidebar', 'vt-side'], ['.bottomnav', 'vt-bnav'], ['.page-head', 'vt-head'], ['.content', 'vt-page']];
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var click = { el: null, kind: null, at: 0 };   // petunjuk dari klik/submit terakhir
  var leaveTimer = 0;

  function store(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* abaikan */ } }
  function load(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function drop(k) { try { sessionStorage.removeItem(k); } catch (e) { /* abaikan */ } }
  function tag(on) {
    NAMES.forEach(function (n) {
      var el = document.querySelector(n[0]);
      if (el) el.style.viewTransitionName = on ? n[1] : '';
    });
  }
  function staggerOn(kind) {
    if (reduce || kind === 'fade' || kind === 'next' || kind === 'prev') return;
    root.classList.add('vt-in');
    setTimeout(function () { root.classList.remove('vt-in'); }, 1400);
  }

  // ---------- arah menurut urutan menu ----------
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
  function pathKind(to, from) {
    var order = navOrder(), ea = order.indexOf(from), eb = order.indexOf(to);
    if (ea >= 0 && eb >= 0) return eb > ea ? 'down' : 'up';                                // antar menu utama (termasuk Dashboard)
    if (from.length > 1 && to.indexOf(from.replace(/\/$/, '') + '/') === 0) return 'in';   // ke halaman detail/anak
    if (to.length > 1 && from.indexOf(to.replace(/\/$/, '') + '/') === 0) return 'out';    // kembali ke induk
    var a = idxOf(order, from), b = idxOf(order, to);
    if (a >= 0 && b >= 0 && a !== b) return b > a ? 'down' : 'up';
    return 'in';
  }
  function pageNo(u) { return parseInt(u.searchParams.get('page'), 10) || 1; }
  function tabDir(a) {
    var box = a && a.closest ? a.closest('.tabs, .subtabs, [role=tablist]') : null;
    if (!box) return null;
    var list = [].slice.call(box.querySelectorAll('a')), cur = box.querySelector('a.active, a[aria-current]');
    var i = list.indexOf(a), c = list.indexOf(cur);
    return i >= 0 && c >= 0 && i !== c ? (i > c ? 'next' : 'prev') : null;
  }
  // kartu kecil (bukan tabel/daftar besar) -> kandidat shared element
  function morphable(a) {
    var c = a && a.closest ? a.closest('.card, .acc-card, .note-card') : null;
    if (!c || c.querySelector('table')) return null;
    var r = c.getBoundingClientRect();
    return r.height >= 70 && r.height <= 300 && r.width >= 180 ? c : null;
  }
  function classify(a, toUrl, submit) {
    var u, from = location.pathname;
    try { u = new URL(toUrl, location.href); } catch (e) { return { kind: 'down' }; }
    if (submit === 'post') return { kind: 'rise' };
    var tn = a && a.getAttribute ? a.getAttribute('data-tx-nav') : null;   // panah transaksi sebelumnya/berikutnya: geser horizontal
    if (tn === 'next' || tn === 'prev') return { kind: tn };
    if (u.pathname === from) {
      var td = tabDir(a);
      if (td) return { kind: td };
      var pn = pageNo(u), pc = pageNo(new URL(location.href));
      if (pn !== pc) return { kind: pn > pc ? 'next' : 'prev' };
      return { kind: 'fade' };
    }
    var k = pathKind(u.pathname, from);
    if (k !== 'out') {                       // kartu kecil yang diklik "memuai" menjadi kartu halaman tujuan
      var m = morphable(a);
      if (m) return { kind: 'morph', el: m };
    }
    return { kind: k };
  }

  // ---------- respons instan saat klik (semua peramban) ----------
  function centerOf(el) {
    var r = el.getBoundingClientRect();
    return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];
  }
  function stopLeaving() {
    clearTimeout(leaveTimer);
    root.classList.remove('vt-leaving');
    root.removeAttribute('data-vt-go');
    root.style.removeProperty('--vt-lx');
    root.style.removeProperty('--vt-ly');
  }
  function begin(el, kind, hold, pt) {
    var p = pt || centerOf(el);
    click = { el: hold || null, kind: kind, at: Date.now() };
    store(KEY_PT, p[0] + ',' + p[1]);
    if (reduce) return;
    var c = document.querySelector('.content');
    if (c) {
      var r = c.getBoundingClientRect();
      root.style.setProperty('--vt-lx', Math.round(p[0] - r.left) + 'px');
      root.style.setProperty('--vt-ly', Math.round(p[1] - r.top) + 'px');
    }
    root.setAttribute('data-vt-go', kind);
    root.classList.add('vt-leaving');
    clearTimeout(leaveTimer);
    leaveTimer = setTimeout(stopLeaving, 10000); // mis. unduhan: halaman tidak berganti
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
    var u;
    try { u = new URL(a.href, location.href); } catch (err) { return; }
    if (u.origin !== location.origin || /\/export\b/.test(u.pathname)) return;
    if (u.pathname === location.pathname && u.search === location.search) return; // jangkar/halaman yang sama
    var c = classify(a, u.href, null);
    begin(a, c.kind, c.el, [e.clientX, e.clientY]);
    setTimeout(function () { if (e.defaultPrevented) stopLeaving(); }, 0);   // ditangani skrip halaman: batalkan
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || e.defaultPrevented || (f.target && f.target !== '_self')) return;
    if (/\/export\b/.test(f.action || '')) return;
    var s = e.submitter || f.querySelector('[type=submit]');
    var post = (f.method || 'get').toLowerCase() === 'post';
    var c = classify(null, f.action || location.href, post ? 'post' : null);
    begin(s || f, c.kind, null, null);
    setTimeout(function () { if (e.defaultPrevented) stopLeaving(); }, 0);
  }, true);
  window.addEventListener('pageshow', stopLeaving);

  // ---------- prefetch halaman tujuan (peramban Chromium) ----------
  // Saat kursor melayang ~200 ms di atas tautan (atau saat ditekan), HTML halaman tujuan sudah diambil:
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
    staggerOn('in');
    return;
  }

  // ---------- pageswap: halaman lama ----------
  window.addEventListener('pageswap', function (e) {
    if (!e.viewTransition) return;
    var dest = e.activation && e.activation.entry && e.activation.entry.url;
    var nav = e.activation && e.activation.navigationType;
    var fresh = click.kind && Date.now() - click.at < 6000 && nav !== 'traverse';
    var kind;
    if (fresh) kind = click.kind;
    else {                                   // tombol Kembali/Maju atau navigasi tanpa klik
      var to; try { to = new URL(dest || location.href, location.href).pathname; } catch (er) { to = location.pathname; }
      kind = to === location.pathname ? 'fade' : pathKind(to, location.pathname);
      if (nav === 'traverse' && (kind === 'in' || kind === 'down')) kind = kind === 'in' ? 'out' : 'up';
    }
    var hold = fresh && kind === 'morph' && click.el && document.contains(click.el) ? click.el : null;
    if (kind === 'morph' && !hold) kind = 'in';
    store(KEY_KIND, kind);
    if (hold) { hold.style.viewTransitionName = 'vt-shared'; store(KEY_MORPH, '1'); } else drop(KEY_MORPH);
    click = { el: null, kind: null, at: 0 };
    tag(true);
  });

  // ---------- pagereveal: halaman baru ----------
  window.addEventListener('pagereveal', function (e) {
    if (!e.viewTransition) return;
    var kind = load(KEY_KIND) || 'down';
    var shared = null;
    if (kind === 'morph') {
      shared = load(KEY_MORPH) === '1' ? document.querySelector('.content .card, .content .grid > *') : null;
      if (shared) shared.style.viewTransitionName = 'vt-shared'; else kind = 'in';
    }
    // titik asal zoom (relatif terhadap .content) mengikuti titik yang diklik
    var content = document.querySelector('.content');
    var pt = (load(KEY_PT) || '').split(',');
    var px = parseFloat(pt[0]), py = parseFloat(pt[1]);
    var r = content ? content.getBoundingClientRect() : { left: 0, top: 0, width: innerWidth, height: innerHeight };
    var ox = isNaN(px) ? r.width / 2 : Math.max(0, Math.min(r.width, px - r.left));
    var oy = isNaN(py) ? 0 : Math.max(0, Math.min(Math.max(r.height, 400), py - r.top));
    var s = root.style;
    s.setProperty('--vt-ox', Math.round(ox) + 'px');
    s.setProperty('--vt-oy', Math.round(oy) + 'px');
    root.setAttribute('data-vt', kind);
    tag(true);
    staggerOn(kind);
    var done = function () {
      tag(false);
      if (shared) shared.style.viewTransitionName = '';
      root.removeAttribute('data-vt');
      s.removeProperty('--vt-ox');
      s.removeProperty('--vt-oy');
    };
    e.viewTransition.finished.then(done, done);
  });
})();

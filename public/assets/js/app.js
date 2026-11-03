/* Cashflow HME — JS inti: toast, modal, fetch aman (CSRF), sidebar, konfirmasi, format Rupiah. Tanpa pustaka luar. */
(function () {
  'use strict';
  var HME = (window.HME = window.HME || {});
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };
  HME.base = meta('app-base');
  HME.role = meta('user-role');
  HME.csrf = function () { return meta('csrf-token'); };
  HME.url = function (p) { return HME.base + '/' + String(p).replace(/^\//, ''); };

  /* ---------- escape & format ---------- */
  HME.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  // Format Rupiah dari string desimal (tanpa float untuk nilai besar): "1234567.5" -> "Rp 1.234.567,50"
  HME.money = function (v, prefix) {
    if (v === null || v === undefined || v === '') v = '0';
    var s = String(v), neg = false;
    if (s[0] === '-') { neg = true; s = s.slice(1); }
    var parts = s.split('.');
    var int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    var dec = (parts[1] || '').slice(0, 2);
    while (dec.length < 2 && dec.length > 0) dec += '0';
    if (/^0*$/.test(dec)) dec = '';
    if (/^0+$/.test(parts[0]) && dec === '') neg = false;
    return (neg ? '-' : '') + (prefix === false ? '' : 'Rp ') + int + (dec ? ',' + dec : '');
  };
  HME.compact = function (n) {
    n = Number(n) || 0;
    var a = Math.abs(n), s = n < 0 ? '-' : '';
    if (a >= 1e9) return s + (a / 1e9).toFixed(a >= 1e10 ? 0 : 1).replace('.', ',') + ' M';
    if (a >= 1e6) return s + (a / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace('.', ',') + ' jt';
    if (a >= 1e3) return s + Math.round(a / 1e3) + ' rb';
    return s + Math.round(a);
  };
  HME.fmtDate = function (iso) {
    if (!iso) return '-';
    var p = String(iso).slice(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  };

  /* ---------- toast ---------- */
  HME.toast = function (msg, type, ms) {
    var box = document.getElementById('toasts');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 'toast ' + (type || 'info');
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var span = document.createElement('div');
    span.textContent = msg;
    var x = document.createElement('button');
    x.className = 'x'; x.type = 'button'; x.setAttribute('aria-label', 'Tutup'); x.textContent = '×';
    x.addEventListener('click', function () { el.remove(); });
    el.appendChild(span); el.appendChild(x);
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, ms || (type === 'error' ? 8000 : 4500));
  };

  /* ---------- fetch aman ---------- */
  HME.api = function (path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (init.method !== 'GET') {
      init.headers['X-CSRF-Token'] = HME.csrf();
      if (opts.form) { init.body = opts.form; }
      else if (opts.data) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.data); }
    }
    return fetch(HME.url(path), init).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Respons tidak valid dari server.' }; }).then(function (j) {
        j._status = r.status;
        if (r.status === 401 && j.login) { window.location.href = HME.url('/login'); }
        return j;
      });
    });
  };

  /* ---------- modal ---------- */
  var lastFocus = null;
  HME.openModal = function (id) {
    var m = document.getElementById(id);
    if (!m) return;
    lastFocus = document.activeElement;
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    var f = m.querySelector('input:not([type=hidden]),select,textarea,button.btn');
    if (f) setTimeout(function () { f.focus(); }, 30);
  };
  HME.closeModal = function (id) {
    var m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m) return;
    m.classList.remove('open');
    if (!document.querySelector('.modal-back.open')) document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* abaikan */ } }
  };
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-open-modal]');
    if (t) { ev.preventDefault(); HME.openModal(t.getAttribute('data-open-modal')); return; }
    var c = ev.target.closest('[data-close-modal]');
    if (c) { ev.preventDefault(); HME.closeModal(c.closest('.modal-back')); return; }
    if (ev.target.classList && ev.target.classList.contains('modal-back') && !ev.target.hasAttribute('data-static')) HME.closeModal(ev.target);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      var open = document.querySelectorAll('.modal-back.open');
      if (open.length) HME.closeModal(open[open.length - 1]);
    }
  });

  /* ---------- konfirmasi (form[data-confirm]) ---------- */
  var confirmEl = null, confirmCb = null;
  function ensureConfirm() {
    if (confirmEl) return confirmEl;
    confirmEl = document.createElement('div');
    confirmEl.className = 'modal-back';
    confirmEl.id = 'confirmModal';
    confirmEl.innerHTML =
      '<div class="modal sm" role="dialog" aria-modal="true" aria-labelledby="cfTitle">' +
      '<div class="modal-head"><h3 id="cfTitle">Konfirmasi</h3></div>' +
      '<div class="modal-body"><p id="cfMsg" class="mb-0"></p><div id="cfExtra" class="mt-2"></div></div>' +
      '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal>Batal</button>' +
      '<button type="button" class="btn btn-danger" id="cfOk">Ya, lanjutkan</button></div></div>';
    document.body.appendChild(confirmEl);
    confirmEl.querySelector('#cfOk').addEventListener('click', function () {
      var cb = confirmCb; var extra = confirmEl.querySelector('#cfReason');
      if (extra && extra.hasAttribute('required') && !extra.value.trim()) { extra.focus(); HME.toast('Alasan wajib diisi.', 'error'); return; }
      HME.closeModal(confirmEl); confirmCb = null;
      if (cb) cb(extra ? extra.value.trim() : '');
    });
    return confirmEl;
  }
  HME.confirm = function (message, cb, opts) {
    opts = opts || {};
    var el = ensureConfirm();
    el.querySelector('#cfTitle').textContent = opts.title || 'Konfirmasi';
    el.querySelector('#cfMsg').textContent = message;
    var ok = el.querySelector('#cfOk');
    ok.textContent = opts.okText || 'Ya, lanjutkan';
    ok.className = 'btn ' + (opts.danger === false ? '' : 'btn-danger');
    var extra = el.querySelector('#cfExtra');
    extra.innerHTML = opts.reason
      ? '<label class="help" for="cfReason">Alasan' + (opts.reasonRequired ? ' (wajib)' : '') + '</label><input class="input" id="cfReason" maxlength="255"' + (opts.reasonRequired ? ' required' : '') + '>'
      : '';
    confirmCb = cb;
    HME.openModal('confirmModal');
  };
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f.hasAttribute && f.hasAttribute('data-confirm') && !f.__confirmed) {
      ev.preventDefault();
      HME.confirm(f.getAttribute('data-confirm'), function (reason) {
        if (f.hasAttribute('data-reason')) {
          var h = f.querySelector('input[name="reason"]');
          if (h) h.value = reason;
        }
        f.__confirmed = true;
        f.submit();
      }, { title: f.getAttribute('data-confirm-title') || 'Konfirmasi', okText: f.getAttribute('data-confirm-ok') || 'Ya, lanjutkan', reason: f.hasAttribute('data-reason'), reasonRequired: f.getAttribute('data-reason') === 'required' });
      return;
    }
    // cegah double-submit
    if (f.tagName === 'FORM' && !f.hasAttribute('data-ajax')) {
      if (f.__submitting) { ev.preventDefault(); return; }
      f.__submitting = true;
      var b = f.querySelector('button[type=submit]:not([data-no-lock])');
      if (b) { b.setAttribute('disabled', 'disabled'); b.classList.add('loading'); }
      setTimeout(function () { f.__submitting = false; if (b) { b.removeAttribute('disabled'); b.classList.remove('loading'); } }, 15000);
    }
  });

  /* ---------- sidebar ---------- */
  var body = document.body;
  var mq = window.matchMedia('(max-width: 767px)');
  var mid = window.matchMedia('(max-width: 1180px)');
  function toggleMenu() {
    if (mq.matches) { body.classList.toggle('drawer-open'); return; }
    if (mid.matches) { body.classList.toggle('sb-expanded'); return; }
    body.classList.toggle('sb-collapsed');
    try { localStorage.setItem('hme.sb', body.classList.contains('sb-collapsed') ? '1' : '0'); } catch (e) { /* abaikan */ }
  }
  try { if (localStorage.getItem('hme.sb') === '1' && !mid.matches) body.classList.add('sb-collapsed'); } catch (e) { /* abaikan */ }
  var mb = document.getElementById('menuBtn'); if (mb) mb.addEventListener('click', toggleMenu);
  var more = document.getElementById('moreBtn'); if (more) more.addEventListener('click', function () { body.classList.toggle('drawer-open'); });
  var sc = document.getElementById('scrim'); if (sc) sc.addEventListener('click', function () { body.classList.remove('drawer-open', 'sb-expanded'); });

  /* ---------- flash dari server ---------- */
  try {
    var fd = document.getElementById('flash-data');
    if (fd) {
      JSON.parse(fd.textContent || '[]').forEach(function (f) {
        HME.toast(f.message, f.type === 'error' ? 'error' : (f.type === 'success' ? 'success' : (f.type === 'warn' ? 'warn' : 'info')));
      });
    }
  } catch (e) { /* abaikan */ }

  /* ---------- util ---------- */
  HME.debounce = function (fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; };
  HME.qs = function (sel, root) { return (root || document).querySelector(sel); };
  HME.qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
})();

/* filter lanjutan: otomatis terbuka pada layar lebar */
(function () {
  var wide = window.matchMedia('(min-width: 768px)');
  function sync() { if (wide.matches) { document.querySelectorAll('details.collapse-m').forEach(function (d) { d.open = true; }); } }
  sync();
  if (wide.addEventListener) wide.addEventListener('change', sync);
})();

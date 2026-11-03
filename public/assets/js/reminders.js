/* Cashflow HME — Pengingat Pembayaran: satu sumber data (/api/reminders) untuk lonceng topbar dan kartu dashboard.
   Disegarkan saat data Proyeksi berubah (polling berversi realtime), saat tab kembali aktif, dan berkala
   (status Terlambat / Hari ini berubah mengikuti tanggal tanpa ada perubahan data). Hanya baca; tidak ada aksi ubah di sini. */
(function () {
  'use strict';
  var H = window.HME;
  if (!H) return;

  var data = null, listeners = [], busy = false, again = false;
  var btn = document.getElementById('notifBtn');
  var badge = document.getElementById('notifBadge');
  var panel = document.getElementById('notifPanel');
  var body = document.getElementById('notifBody');
  var EMPTY = 'Tidak ada tagihan yang mendekati jatuh tempo';

  function row(it) {
    var pill = it.tone === 'red' ? 'pill-red' : (it.tone === 'amber' ? 'pill-yellow' : 'pill-blue');
    return '<a class="rem-item t-' + H.esc(it.tone) + '" href="' + H.url(it.href || ('/forecast/' + it.id)) + '">' +
      '<i class="rem-bar" aria-hidden="true"></i>' +
      '<div class="rem-main"><div class="rem-t">' + H.esc(it.description) + '</div>' +
      '<div class="rem-s">' + (it.kind === 'debt' ? H.esc(it.tag || 'Hutang') + ' · ' : '') + (it.account ? H.esc(it.account) + ' · ' : '') + 'Jatuh tempo ' + H.fmtDate(it.due) + '</div></div>' +
      '<div class="rem-amt num">' + H.money(it.amount) + '</div>' +
      '<div class="rem-st"><span class="pill ' + pill + '">' + H.esc(it.label) + '</span><small>' + H.esc(it.rel) + '</small></div></a>';
  }

  function renderBell() {
    if (!badge || !data) return;
    var n = data.count || 0;
    badge.hidden = n === 0;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.className = 'notif-badge' + (data.late > 0 ? ' late' : '');
    if (btn) btn.setAttribute('aria-label', 'Pengingat pembayaran' + (n ? ': ' + n + ' tagihan' : ': tidak ada'));
    if (!body) return;
    if (!n) { body.innerHTML = '<div class="rem-empty">' + EMPTY + '</div>'; return; }
    var max = 6;
    body.innerHTML = data.items.slice(0, max).map(row).join('') +
      (n > max ? '<a class="rem-more" href="' + H.url('/') + '">Lihat ' + (n - max) + ' lainnya di Dashboard</a>' : '');
  }

  function emit() { listeners.forEach(function (fn) { try { fn(data); } catch (e) { /* abaikan */ } }); }

  function refresh() {
    if (busy) { again = true; return; }
    busy = true;
    H.api('/api/reminders').then(function (d) {
      if (d && d.ok) { data = d; renderBell(); emit(); }
    }).catch(function () { /* pertahankan data terakhir; coba lagi pada siklus berikutnya */ }).then(function () {
      busy = false;
      if (again) { again = false; refresh(); }
    });
  }

  H.reminders = {
    row: row,
    empty: EMPTY,
    data: function () { return data; },
    refresh: refresh,
    on: function (fn) { listeners.push(fn); if (data) fn(data); }
  };

  /* ---------- panel lonceng ---------- */
  function setOpen(open) {
    if (!panel || !btn) return;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) refresh();
  }
  if (btn && panel) {
    btn.addEventListener('click', function (ev) { ev.stopPropagation(); setOpen(panel.hidden); });
    document.addEventListener('click', function (ev) { if (!panel.hidden && !panel.contains(ev.target)) setOpen(false); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) { setOpen(false); btn.focus(); } });
  }

  /* ---------- pemicu penyegaran ---------- */
  if (H.live) H.live.on(['forecast', 'master', 'debts'], refresh);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  setInterval(function () { if (!document.hidden) refresh(); }, 5 * 60 * 1000);
  refresh();
})();

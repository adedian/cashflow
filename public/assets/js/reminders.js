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
      '<div class="rem-s">' + (it.tag ? H.esc(it.tag) + ' · ' : '') + (it.account ? H.esc(it.account) + ' · ' : '') + 'Jatuh tempo ' + H.fmtDate(it.due) + '</div></div>' +
      '<div class="rem-amt num">' + H.money(it.amount) + '</div>' +
      '<div class="rem-st"><span class="pill ' + pill + '">' + H.esc(it.label) + '</span><small>' + H.esc(it.rel) + '</small></div></a>';
  }

  /* ---------- kartu kategori & detail (dipakai Dashboard dan halaman /reminders) ---------- */
  var ST = [['late', 'Terlambat', 'red'], ['today', 'Hari ini', 'amber'], ['soon', 'Segera', 'blue']];

  function card(c) {
    var cls = 'rc-card' + (c.count ? '' : ' is-empty') + (c.late ? ' t-red' : (c.today || c.urgent ? ' t-amber' : (c.count ? ' t-blue' : '')));
    var badges = ST.map(function (s) {
      return c[s[0]] ? '<span class="rc-b rc-' + s[2] + '">' + c[s[0]] + ' ' + s[1].toLowerCase() + '</span>' : '';
    }).join('');
    return '<a class="' + cls + '" href="' + H.url('/reminders?cat=' + encodeURIComponent(c.key)) + '" data-cat="' + H.esc(c.key) + '" aria-label="' + H.esc(c.label) + ': ' + c.count + ' tagihan">' +
      '<div class="rc-top"><span class="rc-name">' + H.esc(c.label) + '' + '</span><span class="rc-n">' + c.count + '</span></div>' +
      '<div class="rc-amt num">' + (c.count ? H.money(c.total) : 'Rp 0') + '</div>' +
      '<div class="rc-badges">' + (badges || '<span class="rc-ok">' + (c.count ? 'Belum ada yang mendesak' : 'Tidak ada tagihan') + '</span>') + '</div>' +
      (c.next_due ? '<div class="rc-next">Terdekat ' + H.fmtDate(c.next_due) + '</div>' : '') + '</a>';
  }

  function cards(d) {
    var cs = (d && d.categories) || [];
    return '<div class="rc-grid">' + cs.map(card).join('') + '</div>';
  }

  function statusPill(it) {
    var pill = it.tone === 'red' ? 'pill-red' : (it.tone === 'amber' ? 'pill-yellow' : 'pill-blue');
    return '<span class="pill ' + pill + '">' + H.esc(it.label) + '</span>';
  }

  function detailRow(it) {
    var part = !!it.part;
    return '<tr data-href="' + H.url(it.href) + '" class="t-' + H.esc(it.tone) + '">' +
      '<td data-label="Jatuh tempo" class="nowrap rd-due"><b>' + H.fmtDate(it.due) + '</b><div class="sub">' + H.esc(it.rel) + '</div></td>' +
      '<td data-label="Transaksi" class="desc"><a href="' + H.url(it.href) + '" style="color:inherit;font-weight:600">' + H.esc(it.name || it.description) + '</a>' +
        (it.ref ? '<div class="sub">' + H.esc(it.ref) + '</div>' : '') + '</td>' +
      '<td data-label="Keterangan" class="rd-note">' + (it.note ? H.esc(it.note) : (it.account ? H.esc(it.account) : '<span class="muted">-</span>')) + '</td>' +
      '<td data-label="Nominal" class="r num rd-amt"><b>' + H.money(it.amount, false) + '</b>' + (part ? '<div class="sub">dari ' + H.money(it.total, false) + '</div>' : '') + '</td>' +
      '<td data-label="Status" class="nowrap rd-st"><span class="pill ' + (part ? 'pill-yellow' : 'pill-gray') + '">' + H.esc(it.pay_status) + '</span> ' + statusPill(it) + '</td></tr>';
  }

  /* detail satu kategori; filter = '' | 'late' | 'today' | 'soon' */
  function detail(d, key, filter) {
    var cat = ((d && d.categories) || []).filter(function (c) { return c.key === key; })[0];
    if (!cat) return '<div class="rem-empty">Kategori tidak dikenal.</div>';
    var all = d.items.filter(function (it) { return it.cat === key; });
    var items = filter ? all.filter(function (it) { return it.status === filter; }) : all;
    var sum = items.reduce(function (a, it) { return a + parseFloat(it.amount); }, 0);
    var chips = [['', 'Semua', all.length]].concat(ST.map(function (s) {
      return [s[0], s[1], all.filter(function (it) { return it.status === s[0]; }).length];
    })).map(function (c) {
      return '<button type="button" class="rd-chip' + ((filter || '') === c[0] ? ' on' : '') + '" data-filter="' + c[0] + '"' + (c[0] && !c[2] ? ' disabled' : '') + '>' + c[1] + ' <b>' + c[2] + '</b></button>';
    }).join('');
    var table = !items.length
      ? '<div class="rem-empty">' + (all.length ? 'Tidak ada tagihan pada filter ini.' : 'Tidak ada tagihan yang perlu dibayar pada kategori ini.') + '</div>'
      : '<div class="table-wrap"><table class="tbl stack rd-tbl"><thead><tr><th class="c-due">Jatuh tempo</th><th>Transaksi</th><th>Keterangan</th><th class="r c-amt">Nominal</th><th class="c-st">Status</th></tr></thead><tbody>' +
        items.map(detailRow).join('') + '</tbody><tfoot><tr><td colspan="3" class="r"><b>Total ' + items.length + ' tagihan</b></td><td class="r num"><b>' + H.money(sum, false) + '</b></td><td></td></tr></tfoot></table></div>';
    return '<div class="rd-head"><a class="btn btn-ghost btn-sm" href="' + H.url('/reminders') + '" data-back>‹ Kembali ke kategori</a>' +
      '<div class="rd-title"><h2>' + H.esc(cat.label) + '</h2><span class="muted">' + cat.count + ' tagihan · ' + H.money(cat.total) + '</span></div></div>' +
      '<div class="rd-chips" role="group" aria-label="Saring status">' + chips + '</div>' + table;
  }

  /* ringkasan satu baris di kepala kartu: total bayar, total tagih (piutang), terlambat, jangka hari */
  function meta(d) {
    if (!d || !d.count) return 'Jangka pengingat ' + ((d && d.days) || '') + ' hari ke depan';
    var out = [];
    if (parseFloat(d.total) > 0) out.push('Bayar ' + H.money(d.total));
    if (d.late) out.push(d.late + ' terlambat');
    out.push('jangka ' + d.days + ' hari');
    return out.join(' · ');
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
      (n > max ? '<a class="rem-more" href="' + H.url('/reminders') + '">Lihat ' + (n - max) + ' lainnya</a>' : '');
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
    cards: cards,
    detail: detail,
    meta: meta,
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
  if (H.live) H.live.on(['forecast', 'master', 'debts', 'transactions'], refresh);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  setInterval(function () { if (!document.hidden) refresh(); }, 5 * 60 * 1000);
  refresh();
})();

(function () {
  'use strict';
  var H = window.HME, $ = H.qs;
  var form = $('#dashFilter');
  var state = { period: new URLSearchParams(location.search).get('period') || '' };
  var info = $('#rangeInfo');
  var busy = false, pending = false;

  function icon(n) { return '<svg class="i i-sm" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }

  function query() {
    var p = new URLSearchParams();
    if (state.period === 'all') p.set('period', 'all');
    else { if (form.from.value) p.set('from', form.from.value); if (form.to.value) p.set('to', form.to.value); }
    if (form.account.value) p.set('account', form.account.value);
    return p.toString();
  }

  // 1234567890 -> "1234 5678 90" (digit dikelompokkan agar mudah dibaca)
  function groupNo(n) { return String(n).replace(/(\d{4})(?=\d)/g, '$1 '); }

  // Kartu Rekening Perusahaan. Nomor lengkap hanya ada di respons bila server mengizinkan untuk peran ini.
  function renderAccounts(list, canManage) {
    var box = $('#accCards');
    var shown = list.filter(function (a) { return a.show; });
    if (!shown.length) { box.innerHTML = '<div class="card" style="grid-column:1/-1"><div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada rekening yang ditampilkan di dashboard.</p></div></div>'; return; }
    box.innerHTML = shown.map(function (a) {
      var isLoan = a.mode === 'liability';
      var cls = isLoan ? 'liab' : (a.kind === 'facility' ? 'fac' : '');
      var type = isLoan ? 'Pinjaman' : (a.kind === 'facility' ? 'Fasilitas' : 'Bank');
      var label = isLoan ? 'Pemakaian pinjaman' : (a.kind === 'facility' ? 'Saldo rekening fasilitas' : 'Saldo rekening');
      var neg = !isLoan && a.balance.charAt(0) === '-';
      var sub = '';
      if (a.limit !== null) sub = '<small>Sisa limit ' + H.money(a.remaining) + ' · limit ' + H.compact(a.limit) + '</small>';
      else sub = '<small>' + (a.last_tx ? 'Transaksi terakhir ' + H.fmtDate(a.last_tx) : 'Belum ada transaksi') + '</small>';
      var no;
      if (a.number) {
        no = '<span class="acc-no num">' + H.esc(groupNo(a.number)) + '</span>' +
          '<button type="button" class="acc-copy" data-copy="' + H.esc(a.number) + '" aria-label="Salin nomor rekening ' + H.esc(a.name) + '" title="Salin nomor">' + icon('copy') + '</button>';
      } else if (a.hint) {
        no = '<span class="acc-no num mask" title="Nomor lengkap tidak ditampilkan">•••• •••• ' + H.esc(a.hint) + '</span>';
      } else {
        no = '<span class="acc-no none">Nomor belum diisi</span>' + (canManage ? '<a class="acc-fill" href="' + H.url('/master?tab=accounts') + '">Isi</a>' : '');
      }
      return '<article class="acc-card compact">' +
        '<div class="acc-top ' + cls + '">' +
        '<div class="acc-line"><span class="acc-bank">' + H.esc(a.bank || type) + '</span>' + (a.bank ? '<span class="acc-type">' + type + '</span>' : '') + '</div>' +
        '<div class="acc-name" title="' + H.esc(a.name) + '">' + H.esc(a.name) + '</div>' +
        '<div class="acc-norow">' + no + '</div>' +
        (a.holder ? '<div class="acc-holder">a.n. ' + H.esc(a.holder) + '</div>' : '') +
        '</div>' +
        '<a class="acc-bot" href="' + H.url('/transactions?account=' + a.id) + '"><div><small>' + label + '</small><div class="bal num ' + (neg ? 'neg' : '') + '">' + H.money(a.balance) + '</div>' + sub + '</div></a>' +
        '</article>';
    }).join('');
  }

  // salin nomor rekening (delegasi: kartu dirender ulang saat data berubah)
  $('#accCards').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy]');
    if (!b) return;
    var v = b.getAttribute('data-copy');
    var done = function () { H.toast('Nomor rekening disalin.', 'success', 2000); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, function () { H.toast('Gagal menyalin nomor.', 'error'); }); return; }
    var t = document.createElement('textarea'); t.value = v; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch (e) { H.toast('Gagal menyalin nomor.', 'error'); }
    t.remove();
  });

  // Pengingat Pembayaran: data dari H.reminders (reminders.js), diperbarui otomatis tanpa refresh halaman
  function renderReminders(d) {
    var list = $('#remList'), cnt = $('#remCount'), meta = $('#remMeta');
    if (!list || !d) return;
    cnt.hidden = !d.count;
    cnt.textContent = d.count;
    cnt.className = 'rem-count' + (d.late ? ' late' : '');
    meta.textContent = H.reminders.meta(d);
    list.innerHTML = H.reminders.cards(d);   // satu kartu per kategori; klik kartu = detail kategori (/reminders?cat=...)
  }
  if (H.reminders) H.reminders.on(renderReminders);

  function kpi(icn, tone, k, v, d) {
    return '<div class="card stat"><div class="k"><span class="ic ' + tone + '">' + icon(icn) + '</span>' + k + '</div><div class="v num">' + v + '</div><div class="d">' + d + '</div></div>';
  }

  function renderKpis(s, range) {
    var per = s.from ? H.fmtDate(s.from) + ' – ' + H.fmtDate(s.to) : 'seluruh data';
    $('#kpis').innerHTML =
      kpi('db', '', 'Saldo awal · semua rekening', H.money(s.opening), s.from ? 'per ' + H.fmtDate(s.from) : 'saldo pembukaan akun') +
      kpi('in', 'g', 'Mutasi masuk usaha', H.money(s.ops_in), 'di luar pembiayaan & transfer') +
      kpi('out', 'r', 'Mutasi keluar usaha', H.money(s.ops_out), s.tx_count + ' transaksi pada ' + per) +
      kpi('card', 'o', 'Saldo akhir · semua rekening', H.money(s.closing), 'Arus kas bersih usaha ' + '<b class="' + (s.ops_net.charAt(0) === '-' ? 'neg' : 'pos') + '">' + H.money(s.ops_net) + '</b>');
    var r = $('#recon');
    r.hidden = false;
    r.innerHTML = '<div class="card-head" style="margin-bottom:8px"><h3>Rekonsiliasi periode</h3><span class="pill pill-blue">Saldo awal + arus kas = saldo akhir</span></div>' +
      '<div class="grid g-4" style="gap:12px">' +
      '<div><div class="muted" style="font-size:12.5px">Saldo awal</div><b class="num">' + H.money(s.opening) + '</b></div>' +
      '<div><div class="muted" style="font-size:12.5px">+ Arus kas bersih usaha</div><b class="num ' + (s.ops_net.charAt(0) === '-' ? 'neg' : 'pos') + '">' + H.money(s.ops_net) + '</b></div>' +
      '<div><div class="muted" style="font-size:12.5px">+ Pembiayaan & transfer (neto)</div><b class="num">' + H.money(addDec(s.fin_net, s.transfer_net)) + '</b></div>' +
      '<div><div class="muted" style="font-size:12.5px">= Saldo akhir</div><b class="num">' + H.money(s.closing) + '</b></div></div>' +
      '<p class="help mt-1 mb-0">Pembiayaan (penarikan/pembayaran pinjaman) dan pindah dana antar rekening tidak dihitung sebagai mutasi masuk/keluar usaha agar tidak terhitung ganda. Saldo gabungan = jumlah saldo rekening bank dan fasilitas.</p>';
  }
  // penjumlahan desimal string sederhana (hanya untuk tampilan gabungan dua nilai 2-desimal dari server)
  function addDec(a, b) {
    var x = Math.round(parseFloat(a) * 100), y = Math.round(parseFloat(b) * 100), t = x + y, neg = t < 0; t = Math.abs(t);
    var s = String(t); while (s.length < 3) s = '0' + s;
    return (neg ? '-' : '') + s.slice(0, -2) + '.' + s.slice(-2);
  }

  function renderLimits(list) {
    var box = $('#limits');
    var fac = list.filter(function (a) { return a.limit !== null; });
    if (!fac.length) { box.innerHTML = '<div class="empty"><p>Tidak ada fasilitas pinjaman.</p></div>'; return; }
    box.innerHTML = fac.map(function (a) {
      var pct = Math.max(0, Math.min(100, Number(a.used_pct) || 0));
      var cls = pct >= 90 ? 'danger' : pct >= 70 ? 'warn' : '';
      return '<div class="limit-item"><div class="top"><b>' + H.esc(a.name) + '</b><span class="num">' + pct.toFixed(1).replace('.', ',') + '%</span></div>' +
        '<div class="bar ' + cls + '" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + pct + '%"></i></div>' +
        '<div class="top mt-1" style="margin-bottom:0"><span class="muted">Terpakai ' + H.money(a.used) + '</span><span class="muted">Sisa ' + H.money(a.remaining) + '</span></div></div>';
    }).join('');
  }

  function renderRecent(list) {
    var box = $('#recent');
    if (!list.length) { box.innerHTML = '<div class="empty"><svg class="i"><use href="#i-list"/></svg><p>Belum ada transaksi pada periode ini.</p></div>'; return; }
    box.innerHTML = list.map(function (t) {
      var inn = Number(t.debit) !== 0, amt = inn ? t.debit : t.credit;
      var ic = t.kind === 'transfer' ? 'swap' : (inn ? 'in' : 'out');
      return '<a class="tx-item" href="' + H.url('/transactions/' + t.id) + '" style="text-decoration:none;color:inherit">' +
        '<div class="cell-flex" style="min-width:0"><span class="tx-ico ' + (inn ? 'in' : 'out') + '">' + icon(ic) + '</span><div style="min-width:0"><div class="t">' + H.esc(t.description || t.party || t.category || '—') + '</div><div class="s">' + H.esc(t.party || '') + (t.party ? ' · ' : '') + H.esc(t.voucher || '') + '</div></div></div>' +
        '<div><div>' + H.esc(t.category || 'Belum dikategorikan') + '</div><div class="s">' + H.fmtDate(t.date) + ' · ' + H.esc(t.account) + '</div></div>' +
        '<div class="a num ' + (inn ? 'pos' : 'neg') + '">' + (inn ? '+' : '−') + H.money(String(amt).replace('-', ''), true) + '</div></a>';
    }).join('');
  }

  function render(d) {
    renderAccounts(d.accounts, d.can_manage);
    renderKpis(d.summary, d.range);
    renderLimits(d.accounts);
    window.HMECharts.bars($('#chBars'), d.monthly);
    window.HMECharts.area($('#chArea'), d.monthly);
    var dw = $('#donutWrap'); dw.innerHTML = '<div class="chart" id="chDonut"></div><div id="donutLegend" style="flex:1;min-width:200px"></div>';
    window.HMECharts.donut($('#chDonut'), d.breakdown, $('#donutLegend'));
    renderRecent(d.recent);
    var s = d.summary;
    document.querySelector('.page-head p').textContent = s.from ? 'Periode ' + H.fmtDate(s.from) + ' – ' + H.fmtDate(s.to) : 'Seluruh data';
  }

  function load(showLoading) {
    if (busy) { pending = true; return Promise.resolve(); }
    busy = true;
    var content = $('#content');
    if (showLoading) content.classList.add('loading-overlay');
    return H.api('/api/dashboard?' + query()).then(function (d) {
      if (!d.ok) { H.toast(d.error || 'Gagal memuat dashboard.', 'error'); return; }
      render(d);
    }).catch(function () { H.toast('Koneksi terputus. Mencoba lagi…', 'error'); }).then(function () {
      busy = false; content.classList.remove('loading-overlay');
      if (pending) { pending = false; load(false); }
    });
  }

  function setUrl() {
    var q = query();
    history.replaceState(null, '', location.pathname + (q ? '?' + q : ''));
  }

  form.addEventListener('submit', function (ev) { ev.preventDefault(); state.period = ''; form.from.disabled = form.to.disabled = false; setUrl(); load(true); });
  form.account.addEventListener('change', function () { setUrl(); load(true); });

  var last = (info.dataset.last || '').slice(0, 10);
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  H.qsa('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var base = last ? new Date(last + 'T00:00:00') : new Date();
      var k = b.dataset.preset;
      state.period = '';
      form.from.disabled = form.to.disabled = false;
      if (k === 'all') { state.period = 'all'; form.from.disabled = form.to.disabled = true; }
      else if (k === 'year') { form.from.value = base.getFullYear() + '-01-01'; form.to.value = base.getFullYear() + '-12-31'; }
      else if (k === 'm3') { form.from.value = ymd(new Date(base.getFullYear(), base.getMonth() - 2, 1)); form.to.value = ymd(new Date(base.getFullYear(), base.getMonth() + 1, 0)); }
      else if (k === 'm1') { form.from.value = ymd(new Date(base.getFullYear(), base.getMonth(), 1)); form.to.value = ymd(new Date(base.getFullYear(), base.getMonth() + 1, 0)); }
      setUrl(); load(true);
    });
  });

  // sinkronisasi realtime: muat ulang HANYA saat data transaksi/master berubah (filter aktif tetap dipakai)
  if (H.live) {
    H.live.on(['transactions', 'master'], function () { load(false); });
  }
  load(false);
})();

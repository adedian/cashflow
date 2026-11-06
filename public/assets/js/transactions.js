(function () {
  'use strict';
  var H = window.HME;

  /* --- daftar: muat ulang hasil (tanpa reload halaman) saat data transaksi berubah di sesi lain --- */
  var res = document.getElementById('txResults');

  /* --- daftar: centang transaksi untuk Cetak Detail (bukti BK/BM). Pilihan bertahan antar halaman/filter selama tab terbuka. --- */
  var SEL_KEY = 'cf_tx_sel', SEL_MAX = 200;
  var sel = [];
  try { var j = JSON.parse(window.sessionStorage.getItem(SEL_KEY) || '[]'); if (Array.isArray(j)) sel = j.map(String); } catch (x) { /* sessionStorage tidak tersedia: pilihan hanya di memori */ }
  function saveSel() { try { window.sessionStorage.setItem(SEL_KEY, JSON.stringify(sel)); } catch (x) { /* abaikan */ } }
  function applySel() {
    if (!res) return;
    var boxes = res.querySelectorAll('.tx-sel:not(:disabled)');
    Array.prototype.forEach.call(boxes, function (b) { b.checked = sel.indexOf(b.value) !== -1; });
    var all = res.querySelector('#txSelAll');
    if (all) {
      var on = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
      all.checked = boxes.length > 0 && on === boxes.length;
      all.indeterminate = on > 0 && on < boxes.length;
    }
    var cnt = res.querySelector('#txSelCount'), btn = res.querySelector('#txPrintSel'), clr = res.querySelector('#txSelClear');
    if (cnt) cnt.textContent = sel.length ? sel.length + ' dipilih' : '';
    if (clr) clr.hidden = !sel.length;
    if (btn) {
      btn.disabled = !sel.length;
      btn.setAttribute('data-export-query', 'ids=' + sel.join(','));
    }
  }
  function toggleId(id, on) {
    var i = sel.indexOf(id);
    if (on && i === -1) {
      if (sel.length >= SEL_MAX) { H.toast('Maksimal ' + SEL_MAX + ' bukti sekali cetak.', 'warn', 3500); return false; }
      sel.push(id);
    }
    if (!on && i !== -1) sel.splice(i, 1);
    return true;
  }
  if (res) {
    res.addEventListener('change', function (e) {
      var t = e.target;
      if (t.classList.contains('tx-sel')) {
        if (!toggleId(t.value, t.checked)) t.checked = false;
      } else if (t.id === 'txSelAll') {
        Array.prototype.forEach.call(res.querySelectorAll('.tx-sel:not(:disabled)'), function (b) { if (toggleId(b.value, t.checked)) b.checked = t.checked; });
      } else return;
      saveSel(); applySel();
    });
    res.addEventListener('click', function (e) {
      if (e.target.id === 'txSelClear') { sel = []; saveSel(); applySel(); }
    });
    applySel();
  }

  if (res && H.live) {
    var busy = false;
    H.live.on(['transactions', 'master'], function () {
      if (busy) return;
      busy = true;
      var q = res.getAttribute('data-query') || '';
      fetch(H.url('/transactions') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (r.status === 401) { window.location.href = H.url('/login'); return null; } return r.ok ? r.text() : null; })
        .then(function (html) {
          if (html !== null) { res.innerHTML = html; applySel(); H.toast('Data transaksi diperbarui.', 'info', 2500); }
        })
        .catch(function () { /* koneksi terputus: coba lagi pada deteksi perubahan berikutnya */ })
        .then(function () { busy = false; });
    });
  }

  /* --- filter: kirim otomatis saat dropdown berubah --- */
  var ff = document.getElementById('txFilter');
  if (ff) {
    ['account', 'dir', 'category', 'project'].forEach(function (n) {
      var el = ff.elements[n]; if (el) el.addEventListener('change', function () { ff.submit(); });
    });
  }

  /* --- formulir: uraian banyak baris (uraian + jumlah), total otomatis. Server memvalidasi ulang. --- */
  var num = function (v) { v = String(v || '').replace(/Rp|\s/g, ''); if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(v)) v = v.replace(/\./g, '').replace(',', '.'); else v = v.replace(',', '.'); var f = parseFloat(v); return isNaN(f) ? null : f; };
  var onLinesChange = null;       // diisi blok No. Invoice/PO (cek sisa terhadap total)
  var refreshLines = function () {};   // diisi saat baris uraian ada: hitung ulang total (dipanggil setelah isian otomatis)
  var lineBox = document.getElementById('lineRows');
  function totalNum() {
    var t = 0;
    if (lineBox) Array.prototype.forEach.call(lineBox.querySelectorAll('.line-amt'), function (i) { var n = num(i.value); if (n !== null) t += n; });
    return Math.round(t * 100) / 100;
  }
  if (lineBox) {
    var totalEl = document.getElementById('txTotal');
    var addBtn = document.getElementById('lineAdd');
    var tpl = document.getElementById('lineTpl');
    var max = parseInt(document.getElementById('lines').getAttribute('data-max'), 10) || 30;
    var refresh = function () {
      var rows = lineBox.querySelectorAll('.line-row');
      // id lama (description/amount) selalu menempel pada baris pertama: dipakai label, isian otomatis dari No. Invoice/PO
      Array.prototype.forEach.call(rows, function (r, i) {
        var d = r.querySelector('.line-desc'), a = r.querySelector('.line-amt');
        if (i === 0) { d.id = 'description'; a.id = 'amount'; } else { d.removeAttribute('id'); a.removeAttribute('id'); }
      });
      if (totalEl) { var t = totalNum(); totalEl.value = t.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); totalEl.classList.toggle('neg', t < 0); }
      if (addBtn) addBtn.disabled = rows.length >= max;
      if (onLinesChange) onLinesChange();
    };
    lineBox.addEventListener('input', function (e) {
      if (e.target.dataset && e.target.dataset.auto) delete e.target.dataset.auto;   // ketikan pengguna menggantikan isian otomatis
      if (e.target.classList.contains('line-amt')) refresh();
    });
    lineBox.addEventListener('focusout', function (e) {
      var amt = e.target;
      if (!amt.classList || !amt.classList.contains('line-amt')) return;
      var s = amt.value.trim().replace(/Rp|\s/g, '');
      if (/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(s)) return;      // sudah format Indonesia
      if (/^-?\d+(\.\d{1,2})?$/.test(s)) {
        var neg = s[0] === '-'; if (neg) s = s.slice(1);
        var p = s.split('.'); var i = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        amt.value = (neg ? '-' : '') + i + (p[1] ? ',' + p[1] : '');
      }
      refresh();
    });
    lineBox.addEventListener('click', function (e) {
      var del = e.target.closest ? e.target.closest('[data-line-del]') : null;
      if (!del) return;
      var row = del.closest('.line-row');
      if (lineBox.querySelectorAll('.line-row').length > 1) { row.parentNode.removeChild(row); }
      else {
        Array.prototype.forEach.call(row.querySelectorAll('input'), function (i) { i.value = ''; delete i.dataset.auto; });
        Array.prototype.forEach.call(row.querySelectorAll('select'), function (s) { s.value = ''; s.dispatchEvent(new Event('change', { bubbles: true })); });
      }
      refresh();
    });
    if (addBtn && tpl) addBtn.addEventListener('click', function () {
      if (lineBox.querySelectorAll('.line-row').length >= max) return;
      lineBox.appendChild(tpl.content.cloneNode(true));
      if (window.HMECombo) window.HMECombo.scan(lineBox.lastElementChild);   // dropdown kategori/proyek baris baru = kotak pencarian juga
      refresh();
      var rows = lineBox.querySelectorAll('.line-row');
      rows[rows.length - 1].querySelector('.line-desc').focus();
    });
    refreshLines = refresh;
    refresh();
  }

  /* --- formulir: No. Invoice/PO (dropdown dinamis) — isi otomatis & ganti jenis transaksi --- */
  var refBox = document.getElementById('ref_combo');
  if (refBox) {
    var hint = document.getElementById('refHint');
    // description/amount = baris uraian pertama (id-nya selalu menempel di baris pertama; dicari saat dipakai)
    var fld = { counterparty: document.getElementById('counterparty'), account: document.getElementById('account_id'),
      get description() { return document.getElementById('description'); }, get amount() { return document.getElementById('amount'); } };
    var picked = null;
    (function () { var n = fld.counterparty; if (n) { n.addEventListener('input', function () { delete n.dataset.auto; }); n.addEventListener('change', function () { delete n.dataset.auto; }); } })();
    if (fld.account) fld.account.addEventListener('change', function () { delete fld.account.dataset.auto; });
    var fmt = function (n) { return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); };
    function setHint(text, warn) { if (!hint) return; hint.hidden = !text; hint.textContent = text || ''; hint.classList.toggle('warn', !!warn); }
    function checkAmount() {
      if (!picked || !lineBox) return;
      var a = totalNum(), rem = parseFloat(picked.remaining);
      var base = 'Sisa ' + picked.remaining_fmt + ' dari ' + picked.amount_fmt + '. Isi nominal sesuai yang benar-benar ' + (picked.direction === 'out' ? 'dibayar' : 'diterima') + ' (boleh sebagian).';
      if (a > rem + 0.004) setHint('Nominal ' + fmt(a) + ' melebihi sisa ' + picked.remaining_fmt + ' — akan ditolak saat disimpan.', true);
      else setHint(base, false);
    }
    function setVal(n, val) {
      if (n.tagName === 'SELECT') {
        var has = false;
        Array.prototype.forEach.call(n.options, function (o) { if (o.value.toLowerCase() === String(val).toLowerCase()) { n.value = o.value; has = true; } });
        if (!has) { n.appendChild(new Option(val, val)); n.value = val; }
        n.dispatchEvent(new Event('change', { bubbles: true }));
      } else { n.value = val; }
    }
    function fill(n, val) { if (!n || val == null || val === '') return; if (n.value.trim() === '' || n.dataset.auto === '1') { setVal(n, val); n.dataset.auto = '1'; } }
    function unfill() { ['counterparty', 'description', 'amount', 'account'].forEach(function (k) { var n = fld[k]; if (n && n.dataset.auto === '1') { if (n.tagName === 'SELECT') { n.value = ''; n.dispatchEvent(new Event('change', { bubbles: true })); } else { n.value = ''; } delete n.dataset.auto; } }); }
    document.addEventListener('combo:pick', function (e) {
      if (e.target !== refBox) return;
      var it = e.detail;
      unfill();
      picked = it;
      if (!it) { setHint('', false); refreshLines(); return; }
      fill(fld.counterparty, it.party, 'counterparty');
      var d = it.group === 'Hutang' ? 'Pembayaran hutang ' + (it.party || '') + (it.description ? ' — ' + it.description : '') : it.group === 'Piutang' ? 'Penerimaan piutang ' + (it.party || '') + (it.ref_no ? ' — ' + it.ref_no : '') : (it.title || '');
      fill(fld.description, d.slice(0, 500), 'description');
      fill(fld.amount, it.remaining_fmt.replace(/^Rp\s*/, ''), 'amount');
      if (it.account_id) fill(fld.account, String(it.account_id), 'account');
      refreshLines();   // total + cek sisa (checkAmount dipanggil lewat onLinesChange)
      checkAmount();
    });
    onLinesChange = checkAmount;
    // nilai awal (form ubah / tombol Bayar·Terima·Realisasikan): tampilkan info sisa tanpa mengubah isian
    try { var init = JSON.parse(refBox.getAttribute('data-selected') || 'null'); if (init) { picked = init; checkAmount(); } } catch (x) { /* abaikan */ }
    // ganti jenis transaksi: referensi lama tidak lagi sesuai -> kosongkan, daftar dimuat ulang dari sumber yang relevan
    Array.prototype.forEach.call(document.querySelectorAll('input[name="direction"]'), function (r) {
      r.addEventListener('change', function () {
        if (refBox.comboApi && refBox.comboApi.value()) {
          refBox.comboApi.clear();
          setHint('Jenis transaksi diubah — referensi sebelumnya dikosongkan. Pilih ulang sesuai jenis yang baru.', true);
        }
        if (refBox.comboApi) refBox.comboApi.reload();
      });
    });
  }
})();

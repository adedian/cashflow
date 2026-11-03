(function () {
  'use strict';
  var H = window.HME;

  /* --- daftar: muat ulang hasil (tanpa reload halaman) saat data transaksi berubah di sesi lain --- */
  var res = document.getElementById('txResults');
  if (res && H.live) {
    var busy = false;
    H.live.on(['transactions', 'master'], function () {
      if (busy) return;
      busy = true;
      var q = res.getAttribute('data-query') || '';
      fetch(H.url('/transactions') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (r.status === 401) { window.location.href = H.url('/login'); return null; } return r.ok ? r.text() : null; })
        .then(function (html) {
          if (html !== null) { res.innerHTML = html; H.toast('Data transaksi diperbarui.', 'info', 2500); }
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

  /* --- formulir: format nominal saat meninggalkan kolom (tampilan saja; server memvalidasi ulang) --- */
  var amt = document.getElementById('amount');
  if (amt) {
    amt.addEventListener('blur', function () {
      var s = amt.value.trim().replace(/Rp|\s/g, '');
      if (/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(s)) return;      // sudah format Indonesia
      if (/^-?\d+(\.\d{1,2})?$/.test(s)) {
        var neg = s[0] === '-'; if (neg) s = s.slice(1);
        var p = s.split('.'); var i = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        amt.value = (neg ? '-' : '') + i + (p[1] ? ',' + p[1] : '');
      }
    });
  }

  /* --- formulir: No. Invoice/PO (dropdown dinamis) — isi otomatis & ganti jenis transaksi --- */
  var refBox = document.getElementById('ref_combo');
  if (refBox) {
    var hint = document.getElementById('refHint');
    var fld = { counterparty: document.getElementById('counterparty'), description: document.getElementById('description'), amount: document.getElementById('amount'), account: document.getElementById('account_id') };
    var picked = null;
    ['counterparty', 'description', 'amount'].forEach(function (k) {
      var n = fld[k]; if (n) { n.addEventListener('input', function () { delete n.dataset.auto; checkAmount(); }); n.addEventListener('change', function () { delete n.dataset.auto; }); }
    });
    if (fld.account) fld.account.addEventListener('change', function () { delete fld.account.dataset.auto; });
    var num = function (v) { v = String(v || '').replace(/Rp|\s/g, ''); if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(v)) v = v.replace(/\./g, '').replace(',', '.'); else v = v.replace(',', '.'); var f = parseFloat(v); return isNaN(f) ? null : f; };
    var fmt = function (n) { return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); };
    function setHint(text, warn) { if (!hint) return; hint.hidden = !text; hint.textContent = text || ''; hint.classList.toggle('warn', !!warn); }
    function checkAmount() {
      if (!picked || !fld.amount) return;
      var a = num(fld.amount.value), rem = parseFloat(picked.remaining);
      var base = 'Sisa ' + picked.remaining_fmt + ' dari ' + picked.amount_fmt + '. Isi nominal sesuai yang benar-benar ' + (picked.direction === 'out' ? 'dibayar' : 'diterima') + ' (boleh sebagian).';
      if (a !== null && a > rem + 0.004) setHint('Nominal ' + fmt(a) + ' melebihi sisa ' + picked.remaining_fmt + ' — akan ditolak saat disimpan.', true);
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
      if (!it) { setHint('', false); return; }
      fill(fld.counterparty, it.party, 'counterparty');
      var d = it.group === 'Hutang' ? 'Pembayaran hutang ' + (it.party || '') + (it.description ? ' — ' + it.description : '') : it.group === 'Piutang' ? 'Penerimaan piutang ' + (it.party || '') + (it.ref_no ? ' — ' + it.ref_no : '') : (it.title || '');
      fill(fld.description, d.slice(0, 500), 'description');
      fill(fld.amount, it.remaining_fmt.replace(/^Rp\s*/, ''), 'amount');
      if (it.account_id) fill(fld.account, String(it.account_id), 'account');
      checkAmount();
    });
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

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
    ['account', 'dir', 'party', 'category', 'project'].forEach(function (n) {
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
        Array.prototype.forEach.call(row.querySelectorAll('input'), function (i) { if (i.closest('.line-ref')) return; i.value = ''; delete i.dataset.auto; });
        Array.prototype.forEach.call(row.querySelectorAll('select'), function (s) { s.value = ''; s.dispatchEvent(new Event('change', { bubbles: true })); });
        var rr = row.querySelector('.line-ref'); if (rr && rr.comboApi) rr.comboApi.clear();
        var hh = row.querySelector('.line-hint'); if (hh) { hh.hidden = true; hh.textContent = ''; }
      }
      refresh();
    });
    if (addBtn && tpl) addBtn.addEventListener('click', function () {
      if (lineBox.querySelectorAll('.line-row').length >= max) return;
      lineBox.appendChild(tpl.content.cloneNode(true));
      if (window.HMECombo) window.HMECombo.scan(lineBox.lastElementChild);   // dropdown kategori/proyek baris baru = kotak pencarian juga
      refresh();
      var rows = lineBox.querySelectorAll('.line-row');
      var nr = rows[rows.length - 1]; nr.__hintOwn = false;
      nr.querySelector('.line-desc').focus();
    });
    refreshLines = refresh;
    refresh();

    /* --- ketik nama di dropdown Kategori / Proyek → "+ Tambah “…” sebagai baru": simpan ke master lalu langsung dipilih di baris tsb. --- */
    var qaCfg = {
      category: { url: '/api/categories', cls: '.line-cat' },
      project: { url: '/api/projects', cls: '.line-proj' }
    };
    // sisipkan opsi baru ke semua dropdown sejenis (baris yang ada + templat baris baru) agar tersedia di mana-mana
    var addOpt = function (root, sel, id, name, groupLabel) {
      Array.prototype.forEach.call(root.querySelectorAll(sel), function (s) {
        var exists = Array.prototype.some.call(s.options, function (o) { return o.value === String(id); });
        if (exists) return;
        var opt = new Option(name, String(id));
        var host = s;
        if (groupLabel) {
          host = null;
          Array.prototype.forEach.call(s.querySelectorAll('optgroup'), function (g) { if (g.label === groupLabel) host = g; });
          if (!host) { host = document.createElement('optgroup'); host.label = groupLabel; s.appendChild(host); }
        }
        host.appendChild(opt);
      });
    };
    var createLine = function (what, row, name) {
      var c = qaCfg[what], form = new URLSearchParams({ name: name });
      // jenis kategori mengikuti arah transaksi (Mutasi Masuk → Pendapatan, Mutasi Keluar → Biaya); dapat diubah di Master Data → Kategori
      if (what === 'category') { var d = document.querySelector('input[name="direction"]:checked'); form.set('dir', d && d.value === 'in' ? 'in' : 'out'); }
      return HME.api(c.url, { method: 'POST', form: form }).then(function (j) {
        if (!j || !j.ok) return (j && j.error) || 'Gagal menambahkan.';
        var group = what === 'category' ? j.kind_label : null;
        addOpt(lineBox, c.cls, j.id, j.name, group); addOpt(tpl.content, c.cls, j.id, j.name, group);
        var target = row && row.querySelector(c.cls);
        if (target) { target.value = String(j.id); target.dispatchEvent(new Event('change', { bubbles: true })); }
        if (HME.toast) HME.toast(j.created ? '“' + j.name + '” ditambahkan ke master.' : '“' + j.name + '” sudah ada — dipilih.', 'success', 2500);
        return '';
      }).catch(function () { return 'Koneksi terputus — gagal menambahkan.'; });
    };
    lineBox.addEventListener('combo:create', function (e) {
      var s = e.target, row = s.closest ? s.closest('.line-row') : null;
      var what = s.classList && s.classList.contains('line-cat') ? 'category' : s.classList && s.classList.contains('line-proj') ? 'project' : '';
      var name = String((e.detail && e.detail.name) || '').replace(/\s+/g, ' ').trim();
      if (!what || !row || !name) return;
      createLine(what, row, name).then(function (err) { if (err && HME.toast) HME.toast(err, 'error', 4000); });
    });
  }

  /* --- Bank/rekening pihak: pilihan "Tambah “…” sebagai baru" membuka dialog ringkas, simpan ke master lalu langsung dipilih. --- */
  (function () {
    var party = document.getElementById('counterparty'), bank = document.getElementById('counterparty_detail');
    var qb = document.getElementById('quickBank');
    if (!party || !bank || !qb) return;
    var $ = function (id) { return document.getElementById(id); };
    var show = function (box, m) { box.hidden = !m; box.textContent = m || ''; };
    var hasOpt = function (s, v) { return Array.prototype.some.call(s.options, function (o) { return o.value.toLowerCase() === v.toLowerCase(); }); };
    // sisipkan opsi (bila belum ada) ke dropdown asli lalu pilih; combo.js menyinkronkan tampilannya lewat event change
    var pickIn = function (sel, value) {
      Array.prototype.forEach.call(sel.options, function (o) { if (o.value.toLowerCase() === value.toLowerCase()) value = o.value; });
      if (!hasOpt(sel, value)) sel.appendChild(new Option(value, value));
      sel.value = value; delete sel.dataset.auto;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    };
    var done = function (msg) { if (H.toast) H.toast(msg, 'success', 2500); };

    /* bank / rekening pihak */
    var bBank = $('qbBank'), bNo = $('qbNo'), bParty = $('qbParty'), bErr = $('qbErr'), bSave = $('qbSave'), bBusy = false;
    var openBank = function (typed) {
      bBank.value = ''; bNo.value = ''; show(bErr, '');
      // ketikan dari dropdown ("BCA 1234567890"): deret angka 6–30 digit → no. rekening, sisanya → nama bank
      if (typed) {
        var m = String(typed).match(/\d[\d\s.\-]{4,}\d/);
        if (m && /^\d{6,30}$/.test(m[0].replace(/[\s.\-]/g, ''))) { bNo.value = m[0].replace(/[\s.\-]/g, ''); typed = typed.replace(m[0], ' '); }
        bBank.value = typed.replace(/\s+/g, ' ').replace(/^[\s\-–—:]+|[\s\-–—:]+$/g, '');
      }
      // pemilik rekening = pihak yang sedang dipilih di formulir (bila ada di daftar)
      var cur = party.value || '';
      if (cur && !hasOpt(bParty, cur)) bParty.appendChild(new Option(cur, cur));
      bParty.value = cur;
      bParty.dispatchEvent(new Event('change', { bubbles: true }));
      H.openModal('quickBank'); setTimeout(function () { (bBank.value && !bNo.value ? bNo : bBank).focus(); }, 60);
    };
    // ketik di dropdown Bank/rekening → "+ Tambah “…” sebagai baru" membuka dialog dengan ketikan terisi (nama bank + no. rekening wajib)
    bank.addEventListener('combo:create', function (e) { openBank(e.detail && e.detail.name); });
    var saveBank = function () {
      if (bBusy) return;
      var b = bBank.value.replace(/\s+/g, ' ').trim(), no = bNo.value.replace(/[\s.\-]/g, '');
      if (!b) { show(bErr, 'Nama bank wajib diisi.'); bBank.focus(); return; }
      if (!/^\d{6,30}$/.test(no)) { show(bErr, 'Nomor rekening harus berupa 6–30 digit angka.'); bNo.focus(); return; }
      bBusy = true; bSave.disabled = true;
      H.api('/api/counterparties', { method: 'POST', form: new URLSearchParams({ bank: b, account_no: no, party: bParty.value || '' }) }).then(function (j) {
        if (!j || !j.ok) { show(bErr, (j && j.error) || 'Gagal menambahkan.'); return; }
        if (j.party) {   // pihak baru (dibuat bersama rekening) tersedia di dropdown Pihak juga
          [party, bParty].forEach(function (s) { if (!hasOpt(s, j.party)) s.appendChild(new Option(j.party, j.party)); });
        }
        pickIn(bank, j.label);
        done(j.created ? '“' + j.label + '” ditambahkan ke master.' : '“' + j.label + '” sudah ada — dipilih.');
        H.closeModal(qb);
      }).catch(function () { show(bErr, 'Koneksi terputus — gagal menambahkan.'); })
        .then(function () { bBusy = false; bSave.disabled = false; });
    };
    bSave.addEventListener('click', saveBank);
    [bBank, bNo].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveBank(); } }); });
  })();

  /* --- formulir: No. Invoice/PO PER BARIS uraian (dropdown dinamis) — isi otomatis, cek sisa, ganti jenis transaksi, tambah cepat --- */
  if (lineBox && document.querySelector('.line-ref')) {
    var fld = { counterparty: document.getElementById('counterparty'), account: document.getElementById('account_id') };
    (function () { var n = fld.counterparty; if (n) { n.addEventListener('input', function () { delete n.dataset.auto; }); n.addEventListener('change', function () { delete n.dataset.auto; }); } })();
    if (fld.account) fld.account.addEventListener('change', function () { delete fld.account.dataset.auto; });
    var fmt = function (n) { return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); };
    var rowRoot = function (row) { return row.querySelector('.line-ref'); };
    var pickedOf = function (row) { var r = rowRoot(row); return r && r.__picked ? r.__picked : null; };
    var setHint = function (row, text, warn) {
      var h = row.querySelector('.line-hint'); if (!h) return;
      h.hidden = !text; h.textContent = text || ''; h.classList.toggle('warn', !!warn);
    };
    // sisa tiap sumber dibandingkan dengan JUMLAH SEMUA baris yang memilih sumber yang sama
    var checkAll = function () {
      var sums = {}, rows = lineBox.querySelectorAll('.line-row');
      Array.prototype.forEach.call(rows, function (row) {
        var it = pickedOf(row); if (!it) return;
        var n = num(row.querySelector('.line-amt').value);
        sums[it.value] = (sums[it.value] || 0) + (n === null ? 0 : n);
      });
      Array.prototype.forEach.call(rows, function (row) {
        var it = pickedOf(row);
        if (!it) { if (!row.__hintOwn) setHint(row, '', false); return; }
        var a = sums[it.value] || 0, rem = parseFloat(it.remaining);
        if (a > rem + 0.004) setHint(row, 'Jumlah ' + fmt(a) + ' melebihi sisa ' + it.remaining_fmt + ' — akan ditolak saat disimpan.', true);
        else setHint(row, 'Sisa ' + it.remaining_fmt + ' dari ' + it.amount_fmt + '. Isi jumlah sesuai yang benar-benar ' + (it.direction === 'out' ? 'dibayar' : 'diterima') + ' (boleh sebagian).', false);
        row.__hintOwn = false;
      });
    };
    onLinesChange = checkAll;
    var setVal = function (n, val) {
      if (n.tagName === 'SELECT') {
        var has = false;
        Array.prototype.forEach.call(n.options, function (o) { if (o.value.toLowerCase() === String(val).toLowerCase()) { n.value = o.value; has = true; } });
        if (!has) { n.appendChild(new Option(val, val)); n.value = val; }
        n.dispatchEvent(new Event('change', { bubbles: true }));
      } else { n.value = val; }
    };
    var fill = function (n, val) { if (!n || val == null || val === '') return; if (n.value.trim() === '' || n.dataset.auto === '1') { setVal(n, val); n.dataset.auto = '1'; } };
    var unfillNode = function (n) {
      if (!n || n.dataset.auto !== '1') return;
      if (n.tagName === 'SELECT') { n.value = ''; n.dispatchEvent(new Event('change', { bubbles: true })); } else { n.value = ''; }
      delete n.dataset.auto;
    };
    var anyPicked = function () { return Array.prototype.some.call(lineBox.querySelectorAll('.line-row'), function (r) { return !!pickedOf(r); }); };

    lineBox.addEventListener('combo:pick', function (e) {
      var root = e.target && e.target.closest ? e.target.closest('.line-ref') : null;
      if (!root) return;
      var row = root.closest('.line-row'), it = e.detail;
      unfillNode(row.querySelector('.line-desc')); unfillNode(row.querySelector('.line-amt'));
      root.__picked = it;
      if (!it) { if (!anyPicked()) { unfillNode(fld.counterparty); unfillNode(fld.account); } refreshLines(); return; }
      fill(fld.counterparty, it.party);
      var d = it.group === 'Hutang' ? 'Pembayaran hutang ' + (it.party || '') + (it.description ? ' — ' + it.description : '') : it.group === 'Piutang' ? 'Penerimaan piutang ' + (it.party || '') + (it.ref_no ? ' — ' + it.ref_no : '') : (it.title || '');
      fill(row.querySelector('.line-desc'), d.slice(0, 500));
      fill(row.querySelector('.line-amt'), it.remaining_fmt.replace(/^Rp\s*/, ''));
      if (it.account_id) fill(fld.account, String(it.account_id));
      refreshLines();   // total + cek sisa (checkAll dipanggil lewat onLinesChange)
    });
    // nilai awal (form ubah / tombol Bayar·Terima·Realisasikan): tampilkan info sisa tanpa mengubah isian
    Array.prototype.forEach.call(lineBox.querySelectorAll('.line-ref'), function (r) {
      try { r.__picked = JSON.parse(r.getAttribute('data-selected') || 'null'); } catch (x) { r.__picked = null; }
    });
    checkAll();
    // ganti jenis transaksi: referensi lama tidak lagi sesuai -> kosongkan semua baris, daftar dimuat ulang dari sumber yang relevan
    Array.prototype.forEach.call(document.querySelectorAll('input[name="direction"]'), function (r) {
      r.addEventListener('change', function () {
        var cleared = false;
        Array.prototype.forEach.call(lineBox.querySelectorAll('.line-ref'), function (root) {
          if (root.comboApi && root.comboApi.value()) { root.comboApi.clear(); cleared = true; }
          if (root.comboApi) root.comboApi.reload();
        });
        if (cleared) {
          var first = lineBox.querySelector('.line-row');
          if (first) { setHint(first, 'Jenis transaksi diubah — No. invoice / PO sebelumnya dikosongkan. Pilih ulang sesuai jenis yang baru.', true); first.__hintOwn = true; }
        }
      });
    });

    /* --- ketik No. invoice/PO yang belum ada → "+ Tambah “…” sebagai baru": dialog ringkas, simpan sebagai Hutang/Piutang, langsung dipilih --- */
    var qr = document.getElementById('quickRef');
    if (qr) {
      var $ = function (id) { return document.getElementById(id); };
      var qNo = $('qrNo'), qParty = $('qrParty'), qAmt = $('qrAmt'), qDue = $('qrDue'), qDesc = $('qrDesc'), qErr = $('qrErr'), qSave = $('qrSave'), qBusy = false, qRoot = null, qDir = 'out';
      var qShow = function (m) { qErr.hidden = !m; qErr.textContent = m || ''; };
      var curDir = function () { var d = document.querySelector('input[name="direction"]:checked'); return d && d.value === 'in' ? 'in' : 'out'; };
      var hasOpt = function (s, v) { return Array.prototype.some.call(s.options, function (o) { return o.value.toLowerCase() === v.toLowerCase(); }); };
      var fillParties = function (names, cur) {
        while (qParty.options.length > 1) qParty.remove(1);
        names.forEach(function (n) { qParty.appendChild(new Option(n, n)); });
        if (cur && !hasOpt(qParty, cur)) qParty.appendChild(new Option(cur, cur));
        Array.prototype.forEach.call(qParty.options, function (o) { if (cur && o.value.toLowerCase() === cur.toLowerCase()) qParty.value = o.value; });
        if (!cur) qParty.value = '';
        qParty.dispatchEvent(new Event('change', { bubbles: true }));
      };
      var openRef = function (root, typed) {
        qRoot = root; qDir = curDir(); qShow('');
        var row = root.closest('.line-row'), a = row ? num(row.querySelector('.line-amt').value) : null;
        var out = qDir === 'out';
        $('qrTitle').textContent = out ? 'Tambah Hutang baru' : 'Tambah Piutang baru';
        $('qrKind').textContent = out ? 'Mutasi Keluar → dicatat sebagai Hutang (jenis Usaha). Pembayarannya dicatat lewat transaksi ini.' : 'Mutasi Masuk → dicatat sebagai Piutang Proyek. Penerimaannya dicatat lewat transaksi ini.';
        $('qrKindName').textContent = out ? 'Hutang' : 'Piutang Proyek'; $('qrMenu').textContent = out ? 'Hutang' : 'Piutang';
        $('qrPartyLbl').textContent = out ? 'Supplier' : 'Customer';
        $('qrDueReq').hidden = out;
        var names = []; try { names = JSON.parse(qParty.getAttribute(out ? 'data-suppliers' : 'data-parties') || '[]'); } catch (x) { names = []; }
        fillParties(names, fld.counterparty ? fld.counterparty.value : '');
        qNo.value = typed || ''; qAmt.value = a !== null && a > 0 ? a.toLocaleString('id-ID', { maximumFractionDigits: 2 }) : '';
        qDue.value = ''; qDesc.value = row ? row.querySelector('.line-desc').value.trim().slice(0, 255) : '';
        H.openModal('quickRef'); setTimeout(function () { (qNo.value ? (qParty.value ? qAmt : qParty.parentNode.querySelector('.combo-input') || qParty) : qNo).focus(); }, 60);
      };
      lineBox.addEventListener('combo:create', function (e) {
        var root = e.target && e.target.hasAttribute && e.target.hasAttribute('data-combo-remote') ? e.target : null;
        if (root) openRef(root, String((e.detail && e.detail.name) || '').replace(/\s+/g, ' ').trim());
      });
      // Supplier / Customer baru: ketik namanya di dialog → "Tambah" menyimpannya ke master lebih dulu, lalu dipilih
      qParty.addEventListener('combo:create', function (e) {
        var name = String((e.detail && e.detail.name) || '').replace(/\s+/g, ' ').trim();
        if (!name) return;
        H.api(qDir === 'out' ? '/api/suppliers' : '/api/parties', { method: 'POST', form: new URLSearchParams({ name: name }) }).then(function (j) {
          if (!j || !j.ok) { qShow((j && j.error) || 'Gagal menambahkan.'); return; }
          if (!hasOpt(qParty, j.name)) qParty.appendChild(new Option(j.name, j.name));
          qParty.value = Array.prototype.filter.call(qParty.options, function (o) { return o.value.toLowerCase() === j.name.toLowerCase(); })[0].value;
          qParty.dispatchEvent(new Event('change', { bubbles: true }));
          if (fld.counterparty && !hasOpt(fld.counterparty, j.name)) fld.counterparty.appendChild(new Option(j.name, j.name));
          qShow('');
        }).catch(function () { qShow('Koneksi terputus — gagal menambahkan.'); });
      });
      var saveRef = function () {
        if (qBusy || !qRoot) return;
        var no = qNo.value.replace(/\s+/g, ' ').trim();
        if (!no) { qShow('No. invoice / PO wajib diisi.'); qNo.focus(); return; }
        if (!qParty.value) { qShow((qDir === 'out' ? 'Supplier' : 'Customer') + ' wajib dipilih.'); return; }
        var n = num(qAmt.value);
        if (n === null || n <= 0) { qShow('Nominal harus berupa angka lebih dari nol.'); qAmt.focus(); return; }
        if (qDir === 'in' && !qDue.value) { qShow('Tgl. jatuh tempo wajib diisi untuk Piutang.'); qDue.focus(); return; }
        qBusy = true; qSave.disabled = true; qShow('');
        H.api('/api/refs', { method: 'POST', form: new URLSearchParams({ dir: qDir, ref_no: no, party: qParty.value, amount: qAmt.value, due_date: qDue.value, description: qDesc.value }) }).then(function (j) {
          if (!j || !j.ok) { qShow((j && j.error) || 'Gagal menambahkan.'); return; }
          if (fld.counterparty && j.item && j.item.party && !hasOpt(fld.counterparty, j.item.party)) fld.counterparty.appendChild(new Option(j.item.party, j.item.party));
          qRoot.comboApi.set(j.item);
          if (H.toast) H.toast(j.created ? '“' + no + '” ditambahkan sebagai ' + (qDir === 'out' ? 'Hutang' : 'Piutang') + ' baru.' : '“' + no + '” sudah ada — dipilih.', 'success', 3000);
          H.closeModal(qr);
        }).catch(function () { qShow('Koneksi terputus — gagal menambahkan.'); })
          .then(function () { qBusy = false; qSave.disabled = false; });
      };
      qSave.addEventListener('click', saveRef);
      [qNo, qAmt, qDue, qDesc].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveRef(); } }); });
      qAmt.addEventListener('blur', function () { var v = num(qAmt.value); if (v !== null) qAmt.value = v.toLocaleString('id-ID', { maximumFractionDigits: 2 }); });
    }
  }
})();

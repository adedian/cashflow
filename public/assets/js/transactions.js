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

    /* --- tombol "+" di samping Kategori / Proyek: tambah ke master lewat dialog, lalu langsung dipilih di baris tsb. --- */
    var qa = document.getElementById('quickAdd');
    if (qa) {
      var qaName = document.getElementById('qaName'), qaHelp = document.getElementById('qaHelp');
      var qaErr = document.getElementById('qaErr'), qaSave = document.getElementById('qaSave'), qaTitle = document.getElementById('qaTitle'), qaLbl = document.getElementById('qaNameLbl');
      var qaWhat = 'category', qaRow = null, qaBusy = false;
      var qaCfg = {
        category: { title: 'Tambah kategori', label: 'Nama kategori', url: '/api/categories', cls: '.line-cat', max: 120 },
        project: { title: 'Tambah proyek', label: 'Nama proyek', url: '/api/projects', cls: '.line-proj', max: 160 }
      };
      var showErr = function (m) { qaErr.hidden = !m; qaErr.textContent = m || ''; };
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
      var openQuick = function (what, row) {
        qaWhat = what; qaRow = row; var c = qaCfg[what];
        qaTitle.textContent = c.title; qaLbl.firstChild.textContent = c.label + ' ';
        qaName.maxLength = c.max; qaName.value = ''; showErr('');
        if (qaHelp) qaHelp.textContent = what === 'category'
          ? 'Tersimpan ke Master Data dan langsung dipilih pada baris uraian ini. Jenis kategori mengikuti arah transaksi (Mutasi Masuk → Pendapatan, Mutasi Keluar → Biaya); dapat diubah di Master Data → Kategori.'
          : 'Tersimpan ke Master Data dan langsung dipilih pada baris uraian ini.';
        HME.openModal('quickAdd');
        setTimeout(function () { qaName.focus(); }, 60);
      };
      lineBox.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-quick-add]') : null;
        if (b) openQuick(b.getAttribute('data-quick-add'), b.closest('.line-row'));
      });
      // simpan ke master lalu pilih di baris tsb; dipakai dialog "+" maupun pilihan "Tambah “…” sebagai baru" di dropdown
      var createLine = function (what, row, name) {
        var c = qaCfg[what], form = new URLSearchParams({ name: name });
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
      var saveQuick = function () {
        if (qaBusy) return;
        var name = qaName.value.replace(/\s+/g, ' ').trim();
        if (!name) { showErr('Nama wajib diisi.'); qaName.focus(); return; }
        qaBusy = true; qaSave.disabled = true;
        createLine(qaWhat, qaRow, name).then(function (err) {
          if (err) showErr(err); else HME.closeModal(qa);
          qaBusy = false; qaSave.disabled = false;
        });
      };
      // ketik nama di dropdown Kategori/Proyek → "+ Tambah “…” sebagai baru"
      lineBox.addEventListener('combo:create', function (e) {
        var s = e.target, row = s.closest ? s.closest('.line-row') : null;
        var what = s.classList.contains('line-cat') ? 'category' : s.classList.contains('line-proj') ? 'project' : '';
        var name = String((e.detail && e.detail.name) || '').replace(/\s+/g, ' ').trim();
        if (!what || !row || !name) return;
        createLine(what, row, name).then(function (err) { if (err && HME.toast) HME.toast(err, 'error', 4000); });
      });
      qaSave.addEventListener('click', saveQuick);
      qaName.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveQuick(); } });
    }
  }

  /* --- tombol "+" di samping Pihak & Bank/rekening pihak: tambah ke master lewat dialog ringkas, lalu langsung dipilih. --- */
  (function () {
    var party = document.getElementById('counterparty'), bank = document.getElementById('counterparty_detail');
    var qp = document.getElementById('quickParty'), qb = document.getElementById('quickBank');
    if (!party || !bank || !qp || !qb) return;
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

    /* pihak */
    var pName = $('qpName'), pErr = $('qpErr'), pSave = $('qpSave'), pBusy = false;
    var openParty = function () { pName.value = ''; show(pErr, ''); H.openModal('quickParty'); setTimeout(function () { pName.focus(); }, 60); };
    var saveParty = function () {
      if (pBusy) return;
      var name = pName.value.replace(/\s+/g, ' ').trim();
      if (!name) { show(pErr, 'Nama pihak wajib diisi.'); pName.focus(); return; }
      if (name.length > 255) { show(pErr, 'Nama pihak maksimal 255 karakter.'); return; }
      pBusy = true; pSave.disabled = true;
      H.api('/api/parties', { method: 'POST', form: new URLSearchParams({ name: name }) }).then(function (j) {
        if (!j || !j.ok) { show(pErr, (j && j.error) || 'Gagal menambahkan.'); return; }
        var bp = $('qbParty');   // opsi baru juga tersedia di dropdown pemilik rekening pada dialog Bank
        if (bp && !hasOpt(bp, j.name)) bp.appendChild(new Option(j.name, j.name));
        pickIn(party, j.name);
        done(j.created ? '“' + j.name + '” ditambahkan ke master.' : '“' + j.name + '” sudah ada — dipilih.');
        H.closeModal(qp);
      }).catch(function () { show(pErr, 'Koneksi terputus — gagal menambahkan.'); })
        .then(function () { pBusy = false; pSave.disabled = false; });
    };
    pSave.addEventListener('click', saveParty);
    pName.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveParty(); } });

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

    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-quick-master]') : null;
      if (!b) return;
      if (b.getAttribute('data-quick-master') === 'party') openParty(); else openBank('');
    });
  })();

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

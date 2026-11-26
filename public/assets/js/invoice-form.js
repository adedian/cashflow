/* Formulir Piutang Lampu: baris barang (tambah/hapus), Jumlah = Qty × Harga, Total, Tgl. jatuh tempo = Tgl. nota + hari, Sisa.
   Hanya pratinjau — server menghitung ulang semuanya saat disimpan. */
(function () {
  'use strict';
  var H = window.HME;
  var form = document.getElementById('lampuForm');
  if (!form) return;
  var tbody = document.querySelector('#itemsTbl tbody');
  var tpl = document.getElementById('itemTpl');
  var legacy = document.getElementById('legacyAmount');

  // "1.250.000,5" / "1250000.5" -> sen (integer) ; null bila tidak valid
  function cents(raw) {
    var s = String(raw || '').replace(/Rp|\s/gi, '');
    if (s === '') return null;
    if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^\d+,\d{1,2}$/.test(s)) s = s.replace(',', '.');
    if (!/^\d{1,16}(\.\d{1,2})?$/.test(s)) return null;
    var p = s.split('.');
    return Number(p[0]) * 100 + Number(((p[1] || '') + '00').slice(0, 2));
  }
  function fmt(c) {
    var neg = c < 0; c = Math.abs(Math.round(c));
    return (neg ? '-' : '') + H.money(Math.floor(c / 100) + '.' + String(c % 100).padStart(2, '0'), false);
  }

  function recalc() {
    var total = 0, any = false;
    Array.prototype.forEach.call(tbody.rows, function (tr, i) {
      tr.querySelector('.ln').textContent = i + 1;
      var q = cents(tr.querySelector('[name="item_qty[]"]').value);
      var p = cents(tr.querySelector('[name="item_price[]"]').value);
      var sub = q !== null && p !== null ? Math.round(q * p / 100) : null;
      if (sub !== null) {
        // diskon: "10%" (persen dari Qty × harga) atau nominal rupiah; isian tak valid dianggap 0 (server yang memvalidasi)
        var dr = tr.querySelector('[name="item_disc[]"]').value.trim(), d = 0;
        if (dr.slice(-1) === '%') { var pc = cents(dr.slice(0, -1)); d = pc !== null ? Math.round(sub * (pc / 100) / 100) : 0; }
        else if (dr !== '') { d = cents(dr) || 0; }
        sub = Math.max(sub - d, 0);
      }
      tr.querySelector('.sub').textContent = sub === null ? '-' : fmt(sub);
      if (sub !== null) { total += sub; any = true; }
    });
    if (legacy) legacy.style.display = any ? 'none' : '';
    if (!any && legacy) total = cents(document.getElementById('amount').value) || 0;
    document.getElementById('itemsTotal').textContent = any ? fmt(total) : (legacy ? fmt(total) + ' (data lama)' : '-');
    var paid = cents(document.getElementById('paid').value) || 0;
    document.getElementById('remainView').value = (any || legacy) ? fmt(Math.max(total - paid, 0)) : '';
  }

  function due() {
    var d = document.getElementById('invoice_date').value;
    var n = document.getElementById('due_days').value.trim();
    var out = document.getElementById('due_date_view');
    if (n === '') { out.value = out.getAttribute('data-saved') ? H.fmtDate(out.getAttribute('data-saved')) : ''; return; }
    if (!d || !/^\d{1,4}$/.test(n)) { out.value = ''; return; }
    var t = new Date(d + 'T00:00:00Z');
    t.setUTCDate(t.getUTCDate() + Number(n));
    out.value = H.fmtDate(t.toISOString().slice(0, 10));
  }

  function addRow() {
    var tr = tpl.content.firstElementChild.cloneNode(true);
    tbody.appendChild(tr);
    if (window.HMECombo) window.HMECombo.scan(tr);   // baris dari <template> tidak ikut dipercantik saat halaman dimuat
    recalc();
    var f = tr.querySelector('.combo-input') || tr.querySelector('[name="item_name[]"]');
    if (f) f.focus();
  }

  document.getElementById('addItem').addEventListener('click', addRow);
  tbody.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-del-item]');
    if (!b) return;
    b.closest('tr').remove();
    if (!tbody.rows.length && !legacy) addRow();
    recalc();
  });
  form.addEventListener('input', function (ev) {
    if (ev.target.id === 'invoice_date' || ev.target.id === 'due_days') due(); else recalc();
  });
  form.addEventListener('change', function (ev) {
    if (ev.target.id === 'invoice_date') due();
    if (ev.target.name === 'item_name[]') {
      // barang dipilih: harga satuan acuan dari master mengisi kolom harga bila masih kosong (tidak menimpa isian pengguna)
      var po = ev.target.selectedOptions[0], pr = ev.target.closest('tr').querySelector('[name="item_price[]"]');
      if (po && po.getAttribute('data-price') && pr && pr.value.trim() === '') pr.value = po.getAttribute('data-price');
      recalc();
    }
    if (ev.target.id === 'pay_tx_id') {
      // kode bukti dipilih: tgl. bayar & dibayar diambil dari transaksinya
      var o = ev.target.selectedOptions[0];
      if (o && o.value) {
        document.getElementById('paid_date').value = o.getAttribute('data-date');
        document.getElementById('paid').value = o.getAttribute('data-amount');
        recalc();
      }
    }
  });
  recalc();
  due();
})();

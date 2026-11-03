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
    recalc();
    tr.querySelector('[name="item_name[]"]').focus();
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

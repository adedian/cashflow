(function () {
  'use strict';
  var H = window.HME;
  var res = document.getElementById('invResults');
  var f = document.getElementById('invFilter');
  if (f && f.elements.status) f.elements.status.addEventListener('change', function () { f.submit(); });
  document.addEventListener('click', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr[data-href]');
    if (!tr || ev.target.closest('a,button,form,input,select')) return;
    window.location.href = tr.getAttribute('data-href');
  });
  // Form Tambah Invoice: pilihan jenis "Invoice" / "Invoice Belum Terbit" -> jatuh tempo & pembayaran hanya untuk invoice terbit
  var radios = document.querySelectorAll('[data-issue-radio]');
  if (radios.length) {
    var help = document.getElementById('issueHelp');
    var txt = {
      terbit: 'Invoice resmi: tanggal jatuh tempo wajib; masuk daftar piutang dan Proyeksi.',
      belum_terbit: 'Belum diterbitkan: tanggal jatuh tempo tidak wajib, status otomatis “Belum Terbit”, tidak membuat pengingat atau item Proyeksi, dan tidak dihitung sebagai piutang. Terbitkan nanti dari daftar “Invoice Belum Terbit”.'
    };
    var apply = function () {
      var ub = false;
      radios.forEach(function (r) { if (r.checked && r.value === 'belum_terbit') ub = true; });
      ['dueField', 'paidField', 'knownField'].forEach(function (id) {
        var box = document.getElementById(id);
        if (!box) return;
        box.hidden = ub;
        box.querySelectorAll('input').forEach(function (i) { i.disabled = ub; });
      });
      if (help) help.textContent = ub ? txt.belum_terbit : txt.terbit;
    };
    radios.forEach(function (r) { r.addEventListener('change', apply); });
    apply();
  }
  if (res && H.live) {
    H.live.on(['invoices'], function () {
      var q = res.getAttribute('data-query') || '';
      fetch(H.url('/invoices') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) { if (html !== null) { res.innerHTML = html; H.toast('Daftar invoice diperbarui.', 'info', 2500); } })
        .catch(function () { /* coba lagi pada perubahan berikutnya */ });
    });
  }
})();

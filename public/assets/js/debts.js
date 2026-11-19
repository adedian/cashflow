(function () {
  'use strict';
  var H = window.HME;
  var res = document.getElementById('debtResults');
  var f = document.getElementById('debtFilter');
  if (f && f.elements.status) f.elements.status.addEventListener('change', function () { f.submit(); });

  /* form hutang: label Hutang Direksi (Tanggal setoran, Rekening penerima) mengikuti pilihan jenis */
  var cat = document.querySelector('form select[name="category"]');
  if (cat) {
    cat.addEventListener('change', function () {
      var loan = cat.value === 'direksi';
      document.querySelectorAll('[data-loan]').forEach(function (el) { el.textContent = el.getAttribute(loan ? 'data-loan' : 'data-std'); });
      document.querySelectorAll('[data-loan-only]').forEach(function (el) { el.hidden = !loan; });
      document.querySelectorAll('[data-std-only]').forEach(function (el) { el.hidden = loan; });
    });
  }

  /* klik baris membuka detail (tautan & tombol di dalam baris tetap bekerja sendiri) */
  document.addEventListener('click', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr[data-href]');
    if (!tr || ev.target.closest('a,button,form,input,select')) return;
    window.location.href = tr.getAttribute('data-href');
  });

  if (H && H.live) {
    H.live.on(['debts'], function () {
      if (res) {
        var q = res.getAttribute('data-query') || '';
        fetch(H.url('/debts') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.text() : null; })
          .then(function (html) { if (html !== null) { res.innerHTML = html; H.toast('Daftar hutang diperbarui.', 'info', 2500); } })
          .catch(function () { /* coba lagi pada perubahan berikutnya */ });
      } else if (document.getElementById('debtDetail')) {
        H.toast('Data hutang ini baru saja diubah. Muat ulang halaman untuk melihat versi terbaru.', 'info', 6000);
      }
    });
  }
})();

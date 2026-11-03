(function () {
  'use strict';
  var H = window.HME;
  var res = document.getElementById('fcResults');
  if (res && H.live) {
    // proyeksi bergantung pada saldo bank (transaksi) dan item proyeksi
    H.live.on(['forecast', 'transactions'], function () {
      var q = res.getAttribute('data-query') || '';
      fetch(H.url('/forecast') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) { if (html !== null) { res.innerHTML = html; H.toast('Proyeksi diperbarui.', 'info', 2500); } })
        .catch(function () { /* coba lagi pada perubahan berikutnya */ });
    });
  }
})();

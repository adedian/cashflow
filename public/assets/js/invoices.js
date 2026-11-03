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

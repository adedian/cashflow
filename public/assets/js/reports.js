(function () {
  'use strict';
  var H = window.HME;
  var card = document.getElementById('rptCard');
  if (card && H.live) {
    H.live.on(['transactions', 'master'], function () {
      H.api('/api/reports?' + card.getAttribute('data-query')).then(function (j) {
        if (j.ok) { document.getElementById('rptBody').innerHTML = j.html; H.toast('Laporan diperbarui.', 'info', 2500); }
      });
    });
  }
})();

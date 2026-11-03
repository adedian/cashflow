(function () {
  'use strict';
  /* Pemetaan: ganti sheet / jenis data memuat ulang daftar kolom (server menyarankan ulang pemetaan & baris header). */
  var pick = document.getElementById('mapPick');
  if (!pick) return;
  ['sheetSel', 'kindSel'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', function () {
      var h = document.getElementById('hdrSel');
      if (h) h.removeAttribute('name');   // baris header dideteksi ulang untuk sheet/jenis baru
      pick.submit();
    });
  });
})();

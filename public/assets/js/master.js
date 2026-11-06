/* Master Data > Rekening: kolom Limit hanya tampil untuk jenis "Loan"; "Bank HR" tidak memakai limit. */
(function () {
  'use strict';
  var kind = document.getElementById('ak');
  var field = document.getElementById('al-field');
  var input = document.getElementById('al');
  if (!kind || !field || !input) { return; }
  function sync() {
    var loan = kind.value === 'facility';
    field.classList.toggle('hidden', !loan);
    if (!loan) { input.value = ''; }
  }
  kind.addEventListener('change', sync);
  sync();
})();

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

/* Master Data (semua tab): kotak "Cari" di atas tabel — menyaring baris saat mengetik (tanpa memuat ulang halaman).
   Yang dicocokkan: teks sel + isi kolom isian (input) + pilihan yang sedang terpilih pada dropdown. Beberapa kata = semuanya harus cocok.
   Huruf besar/kecil dan aksen diabaikan. Baris pesan kosong (satu sel colspan) tidak ikut disaring. */
(function () {
  'use strict';
  var norm = function (s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  };
  document.querySelectorAll('.content .table-wrap > table.tbl').forEach(function (table, ti) {
    var wrap = table.parentNode, tbody = table.tBodies[0];
    if (!tbody) { return; }
    var rows = [].slice.call(tbody.rows).filter(function (r) { return !(r.cells.length === 1 && r.cells[0].colSpan > 1); });
    if (!rows.length) { return; }

    var bar = document.createElement('div');
    bar.className = 'master-search';
    var id = 'masterSearch' + ti;
    bar.innerHTML = '<label class="sr-only" for="' + id + '">Cari di daftar ini</label>' +
      '<svg class="i" aria-hidden="true"><use href="#i-search"/></svg>' +
      '<input class="input" id="' + id + '" type="search" placeholder="Cari di daftar ini…" autocomplete="off">' +
      '<span class="master-search-n" aria-live="polite"></span>';
    wrap.parentNode.insertBefore(bar, wrap);
    var q = bar.querySelector('input'), count = bar.querySelector('.master-search-n');

    var none = document.createElement('p');
    none.className = 'muted master-search-none';
    none.style.cssText = 'padding:14px 18px;margin:0';
    none.hidden = true;
    none.textContent = 'Tidak ada yang cocok dengan pencarian.';
    wrap.parentNode.insertBefore(none, wrap.nextSibling);

    var base = new Map();   // teks tetap per baris (tanpa kolom isian), dihitung sekali
    var staticText = function (row) {
      if (!base.has(row)) {
        var c = row.cloneNode(true);
        c.querySelectorAll('input,select,textarea,button,form,script,style').forEach(function (n) { n.remove(); });
        base.set(row, c.textContent || '');
      }
      return base.get(row);
    };
    var haystack = function (row) {
      var t = staticText(row);
      row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]),textarea').forEach(function (i) { t += ' ' + i.value; });
      row.querySelectorAll('select').forEach(function (s) { var o = s.options[s.selectedIndex]; if (o) { t += ' ' + o.textContent; } });
      return norm(t);
    };
    var run = function () {
      var words = norm(q.value).split(/\s+/).filter(Boolean), shown = 0;
      rows.forEach(function (row) {
        var hay = words.length ? haystack(row) : '';
        var ok = words.every(function (w) { return hay.indexOf(w) !== -1; });
        row.style.display = ok ? '' : 'none';
        if (ok) { shown++; }
      });
      none.hidden = shown !== 0;
      count.textContent = words.length ? shown + ' dari ' + rows.length : rows.length + ' baris';
    };
    q.addEventListener('input', run);
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    run();
  });
})();

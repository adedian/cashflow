/* Halaman Pengingat: kartu per kategori -> detail kategori (?cat=...). Data dari H.reminders (realtime), tanpa muat ulang halaman. */
(function () {
  'use strict';
  var H = window.HME;
  var root = document.getElementById('remPage');
  if (!H || !H.reminders || !root) return;
  var body = document.getElementById('remBody'), cnt = document.getElementById('remCount'), meta = document.getElementById('remMeta'), ttl = document.getElementById('remPageTitle');
  var cat = root.getAttribute('data-cat') || '';
  var filter = '';

  function render(d) {
    if (!d) return;
    cnt.hidden = !d.count;
    cnt.textContent = d.count;
    cnt.className = 'rem-count' + (d.late ? ' late' : '');
    meta.textContent = H.reminders.meta(d);
    if (cat) {
      ttl.textContent = 'Detail kategori';
      body.innerHTML = H.reminders.detail(d, cat, filter);
    } else {
      ttl.textContent = 'Pengingat per kategori';
      body.innerHTML = H.reminders.cards(d);
    }
  }

  function go(newCat) {
    cat = newCat || '';
    filter = '';
    var u = H.url('/reminders') + (cat ? '?cat=' + encodeURIComponent(cat) : '');
    window.history.pushState({ cat: cat }, '', u);
    render(H.reminders.data());
    window.scrollTo(0, 0);
  }

  root.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[data-cat]');
    if (a && !ev.ctrlKey && !ev.metaKey && !ev.shiftKey) { ev.preventDefault(); go(a.getAttribute('data-cat')); return; }
    var back = ev.target.closest('a[data-back]');
    if (back && !ev.ctrlKey && !ev.metaKey) { ev.preventDefault(); go(''); return; }
    var chip = ev.target.closest('.rd-chip');
    if (chip && !chip.disabled) { filter = chip.getAttribute('data-filter') || ''; render(H.reminders.data()); return; }
    var tr = ev.target.closest('tr[data-href]');
    if (tr && !ev.target.closest('a,button')) window.location.href = tr.getAttribute('data-href');
  });
  window.addEventListener('popstate', function () {
    var m = /[?&]cat=([^&]+)/.exec(window.location.search);
    cat = m ? decodeURIComponent(m[1]) : '';
    filter = '';
    render(H.reminders.data());
  });
  H.reminders.on(render);
})();

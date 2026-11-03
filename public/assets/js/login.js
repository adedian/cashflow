(function () {
  'use strict';
  var pw = document.getElementById('password');
  var tg = document.getElementById('togglePw');
  var form = document.getElementById('loginForm');
  var btn = document.getElementById('loginBtn');
  if (tg && pw) {
    tg.addEventListener('click', function () {
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      tg.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      tg.querySelector('use').setAttribute('href', show ? '#i-eye-off' : '#i-eye');
    });
  }
  if (form && btn) {
    form.addEventListener('submit', function (ev) {
      var u = document.getElementById('username');
      if (!u.value.trim() || !pw.value) {
        ev.preventDefault();
        window.HME.toast('Username dan password wajib diisi.', 'error');
        return;
      }
      btn.classList.add('loading');
      btn.setAttribute('disabled', 'disabled');
      btn.innerHTML = '<span class="spin"></span><span class="lbl">Memproses…</span>';
    });
  }
})();

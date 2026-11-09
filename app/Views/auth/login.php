<?php $splash = empty($error); // tampilkan animasi pembuka hanya saat halaman dibuka, bukan setelah salah password ?>
<?php if ($splash): ?>
<div class="splash" id="splash" role="presentation" aria-hidden="true" title="Ketuk untuk melewati">
  <div class="splash-stage">
    <!-- 1) arus kas: koin berputar mengikuti dua panah melingkar -->
    <div class="splash-orbit">
      <svg class="splash-loop" viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg">
        <g fill="none" stroke="rgba(255,255,255,.75)" stroke-width="3" stroke-linecap="round">
          <path d="M214 85.8A100 100 0 0 1 102.6 218.5"/>
          <path d="M214 85.8A100 100 0 0 1 102.6 218.5" transform="rotate(180 120 120)"/>
        </g>
        <g fill="#ffb300">
          <polygon points="90.8,216.4 103.8,211.6 101.4,225.4"/>
          <polygon points="90.8,216.4 103.8,211.6 101.4,225.4" transform="rotate(180 120 120)"/>
        </g>
      </svg>
      <span class="splash-coin" style="--a:-90deg"><i>Rp</i></span>
      <span class="splash-coin" style="--a:-30deg"><i>Rp</i></span>
      <span class="splash-coin" style="--a:30deg"><i>Rp</i></span>
      <span class="splash-coin" style="--a:90deg"><i>Rp</i></span>
      <span class="splash-coin" style="--a:150deg"><i>Rp</i></span>
      <span class="splash-coin" style="--a:210deg"><i>Rp</i></span>
    </div>
    <!-- 2) koin membentuk segi enam (hexa) -->
    <svg class="splash-hex" viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg">
      <defs><linearGradient id="splHex" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffb300"/><stop offset="1" stop-color="#f57c00"/></linearGradient></defs>
      <polygon class="fill" points="120,50 180.6,85 180.6,155 120,190 59.4,155 59.4,85" fill="url(#splHex)"/>
      <polygon class="edge" pathLength="1" points="120,50 180.6,85 180.6,155 120,190 59.4,155 59.4,85" fill="none" stroke="#fff" stroke-width="4" stroke-linejoin="round"/>
    </svg>
    <!-- 3) logo muncul kecil lalu membesar -->
    <span class="splash-pulse"></span>
    <div class="splash-logo" style="--logo:url('<?= e(asset('images/logo-hme.png')) ?>')"><img src="<?= e(asset('images/logo-hme.png')) ?>" alt=""></div>
  </div>
  <div class="splash-title">Cashflow HME</div>
  <div class="splash-sub">PT Hexa Multi Energi</div>
  <div class="splash-bar"><i></i></div>
</div>
<?php endif; ?>
<section class="login-card<?= $splash ? ' has-splash' : '' ?>">
  <div class="login-left">
    <div class="brand"><img src="<?= e(asset('images/logo-hme.png')) ?>" alt="Logo HME"><span>Cashflow HME</span></div>
    <div class="illus" aria-hidden="true">
      <svg viewBox="0 0 320 260" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="160" cy="236" rx="120" ry="14" fill="rgba(255,255,255,.14)"/>
        <rect x="70" y="30" width="150" height="196" rx="14" fill="#fff" opacity=".96"/>
        <rect x="118" y="18" width="54" height="26" rx="8" fill="#ffb300"/>
        <rect x="132" y="12" width="26" height="14" rx="5" fill="#f57c00"/>
        <rect x="90" y="64" width="110" height="10" rx="5" fill="#cfe0ff"/>
        <path d="M92 104l7 7 13-14" stroke="#0b5cff" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="124" y="98" width="76" height="10" rx="5" fill="#cfe0ff"/>
        <path d="M92 142l7 7 13-14" stroke="#0b5cff" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="124" y="136" width="60" height="10" rx="5" fill="#cfe0ff"/>
        <rect x="92" y="176" width="14" height="30" rx="3" fill="#8fbaff"/>
        <rect x="114" y="164" width="14" height="42" rx="3" fill="#0b5cff"/>
        <rect x="136" y="150" width="14" height="56" rx="3" fill="#f57c00"/>
        <rect x="158" y="170" width="14" height="36" rx="3" fill="#19c6f2"/>
        <circle cx="246" cy="86" r="30" fill="#ffb300"/>
        <text x="246" y="96" text-anchor="middle" font-family="Segoe UI,Arial" font-size="28" font-weight="700" fill="#fff">Rp</text>
        <circle cx="262" cy="170" r="16" fill="rgba(255,255,255,.35)"/>
        <circle cx="48" cy="120" r="10" fill="rgba(255,255,255,.35)"/>
      </svg>
    </div>
    <h2>Selamat datang</h2>
    <p>Pantau arus kas, rekening, limit pinjaman, dan piutang proyek PT Hexa Multi Energi dalam satu tempat.</p>
  </div>

  <div class="login-right">
    <h1>Masuk</h1>
    <p class="sub">Gunakan akun yang diberikan administrator. Pendaftaran mandiri tidak tersedia.</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger mb-2" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" id="loginForm" autocomplete="on" novalidate>
      <?= csrf_field() ?>
      <div class="uline">
        <input type="text" name="username" id="username" placeholder="Username" value="<?= e($username ?? '') ?>" autocomplete="username" maxlength="50" required autofocus aria-label="Username">
        <span class="ico"><svg class="i"><use href="#i-user"/></svg></span>
      </div>
      <div class="uline">
        <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" maxlength="200" required aria-label="Password">
        <button class="ico" type="button" id="togglePw" aria-label="Tampilkan password"><svg class="i"><use href="#i-eye"/></svg></button>
      </div>
      <button class="btn" type="submit" id="loginBtn"><span class="lbl">Masuk</span></button>
    </form>

    <p class="login-note">Lupa password? Hubungi administrator (Super Admin) untuk mengatur ulang.</p>
  </div>
</section>

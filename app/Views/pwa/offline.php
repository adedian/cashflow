<main class="off-card" id="offlineCard" role="main" aria-labelledby="offTitle">
  <div class="off-hero">
    <img class="off-logo" src="<?= e(asset('images/logo-hme.png')) ?>" alt="Cashflow HME" width="64" height="64">
    <div class="off-icon" aria-hidden="true">
      <span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span>
      <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 8.8a15 15 0 0 1 4.2-2.5"/><path d="M10.5 5.1A15 15 0 0 1 22 8.8"/>
        <path d="M5 12.6a10 10 0 0 1 3.2-2"/><path d="M13.3 10.3A10 10 0 0 1 19 12.6"/>
        <path d="M8.5 16.4a5 5 0 0 1 2.5-1.2"/><path d="M15.5 16.4a5 5 0 0 0-1.4-1"/>
        <path d="M12 20h.01"/><path d="M3 3l18 18"/>
      </svg>
    </div>
  </div>

  <div class="off-body">
    <h1 id="offTitle">Anda sedang offline</h1>
    <p class="off-sub">Cashflow HME memerlukan koneksi internet agar data keuangan selalu terbaru dan aman. Tidak ada data yang hilang &mdash; semuanya tetap tersimpan di server.</p>

    <ul class="off-tips">
      <li><span class="tip-ico" aria-hidden="true">1</span><span>Pastikan Wi-Fi atau data seluler Anda aktif</span></li>
      <li><span class="tip-ico" aria-hidden="true">2</span><span>Coba dekatkan perangkat ke router atau pindah jaringan</span></li>
      <li><span class="tip-ico" aria-hidden="true">3</span><span>Setelah tersambung, halaman akan dimuat ulang otomatis</span></li>
    </ul>

    <button type="button" class="btn off-retry" id="offRetry">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/></svg>
      <span class="lbl">Coba lagi</span>
    </button>

    <p class="off-status" id="offStatus" role="status" aria-live="polite"><span class="dot"></span><span class="txt">Menunggu koneksi…</span></p>
  </div>
</main>
<script src="<?= e(asset('js/offline.js')) ?>"></script>

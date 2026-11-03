<?php
/** Tindakan untuk sisa estimasi yang terealisasi sebagian: hanya AKHIRI (Super Admin). Tanpa tindakan = sisa tetap estimasi aktif.
 * Dipakai di detail Proyeksi dan detail Transaksi.
 * @var array $it item forecast_items  @var array $sum ForecastService::summary  @var bool $isAdmin */
if (empty($isAdmin) || empty($sum)) { return; }
$id = (int) $it['id'];
$out = $it['direction'] === 'out';
$rem = money($sum['remaining']);
?>
<?php if ($sum['status'] === 'sebagian'): ?>
<section class="card mt-2 fc-decision" style="max-width:820px" id="fcDecision" aria-label="Akhiri sisa estimasi">
  <div class="card-head"><h2>Sisa estimasi <?= e($rem) ?></h2><span class="pill pill-yellow">Realisasi Sebagian</span></div>
  <p class="mb-1"><b><?= e($it['description']) ?></b>: estimasi <?= e(money($sum['estimate'])) ?>, <?= $out ? 'dibayar' : 'diterima' ?> <?= e(money($sum['realized'])) ?>, sisa <b><?= e($rem) ?></b>. Sisa masih aktif dan dapat direalisasikan lewat menu Transaksi.</p>
  <form method="post" action="<?= e(url('/forecast/' . $id . '/close')) ?>" class="fc-choice" data-confirm="Akhiri sisa <?= e($rem) ?>? Item ini otomatis hilang dari daftar Proyeksi dan tidak muncul di pengingat maupun pilihan realisasi. Tidak ada transaksi atau saldo yang berubah; riwayat tetap tersimpan." data-confirm-title="Akhiri sisa estimasi" data-confirm-ok="Ya, akhiri">
    <?= csrf_field() ?>
    <div class="fc-choice-t"><b>Akhiri</b> — tutup sisa <?= e($rem) ?> karena tidak akan <?= $out ? 'dibayar' : 'diterima' ?> lagi.</div>
    <input class="input" name="note" maxlength="255" placeholder="Catatan penutupan (opsional)" aria-label="Catatan penutupan">
    <button class="btn" type="submit" data-no-lock>Akhiri sisa</button>
  </form>
</section>
<?php elseif ($sum['status'] === 'diakhiri'): ?>
<section class="card mt-2 fc-decision" style="max-width:820px" id="fcDecision" aria-label="Sisa estimasi diakhiri">
  <div class="card-head"><h2>Sisa estimasi diakhiri</h2><span class="pill pill-green">Selesai — Sisa Diakhiri</span></div>
  <p class="mb-1">Sisa <b><?= e(money($sum['closed_remaining'] ?? $sum['remaining'])) ?></b> ditutup pada <?= e(fmt_date($it['closed_at'], 'd/m/Y H:i')) ?>. Item ini tidak tampil di daftar Proyeksi (hanya di riwayat). Tidak ada transaksi, saldo, atau realisasi yang berubah.</p>
  <form method="post" action="<?= e(url('/forecast/' . $id . '/reopen')) ?>" data-confirm="Buka kembali sisa estimasi? Sisa akan menjadi estimasi aktif lagi dan tampil kembali di daftar Proyeksi. Penutupan sebelumnya tetap tercatat di riwayat." data-confirm-title="Buka kembali sisa" data-confirm-ok="Ya, buka kembali">
    <?= csrf_field() ?><button class="btn btn-ghost" type="submit" data-no-lock>Buka kembali sisa</button>
  </form>
</section>
<?php endif; ?>

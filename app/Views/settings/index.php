<?php /** @var string $company @var bool $viewerExport @var int $reminderDays @var array $backups @var array $sys */ ?>
<section class="card" style="max-width:820px">
  <div class="card-head"><h2>Pengaturan umum</h2></div>
  <form method="post" action="<?= e(url('/settings')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field full"><label for="cn">Nama perusahaan (header laporan)</label><input class="input" id="cn" name="company_name" maxlength="120" value="<?= e($company) ?>" required></div>
      <div class="field full"><label class="check"><input type="checkbox" name="viewer_can_export" value="1" <?= $viewerExport ? 'checked' : '' ?>>Izinkan Viewer mencetak/mengunduh PDF & Excel (nomor rekening pihak lain tidak pernah disertakan)</label></div>
      <div class="field"><label for="rd">Jangka Pengingat Pembayaran (hari ke depan)</label><input class="input num" id="rd" name="reminder_days" type="number" min="1" max="60" step="1" value="<?= (int) $reminderDays ?>" required>
        <span class="help">Pengeluaran proyeksi yang jatuh tempo dari hari ini sampai <?= (int) $reminderDays ?> hari ke depan ditampilkan; yang sudah lewat dan belum dibayar ditandai Terlambat. Bawaan 11.</span></div>
    </div>
    <div class="mt-2"><button class="btn" type="submit">Simpan pengaturan</button></div>
  </form>
</section>

<section class="card card-flush">
  <div class="card-head"><div><h2>Backup database</h2><span class="muted" style="font-size:12.5px">Disimpan di folder privat server (tidak dapat diakses lewat web). Berisi password hash pengguna — jangan dibagikan.</span></div>
    <form method="post" action="<?= e(url('/settings/backup')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><svg class="i i-sm"><use href="#i-db"/></svg>Buat backup sekarang</button></form></div>
  <?php if (!$backups): ?><div class="empty"><svg class="i"><use href="#i-db"/></svg><p>Belum ada backup.</p></div><?php else: ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Berkas</th><th>Waktu</th><th class="r">Ukuran</th><th>Verifikasi</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr>
        <td data-label="Berkas" class="desc"><?= e($b['file']) ?><?php if (!empty($b['sha256'])): ?><div class="sub">SHA-256 <?= e(substr($b['sha256'], 0, 16)) ?>…</div><?php endif; ?></td>
        <td data-label="Waktu" class="nowrap"><?= e(fmt_date($b['created_at'], 'd/m/Y H:i')) ?></td>
        <td data-label="Ukuran" class="r num"><?= e(number_format(((int) $b['size']) / 1024, 0, ',', '.')) ?> KB</td>
        <td data-label="Verifikasi"><?php if ($b['verified'] === true): ?><span class="pill pill-green">Terverifikasi</span><?php elseif ($b['verified'] === false): ?><span class="pill pill-red" title="<?= e(implode('; ', $b['problems'] ?? [])) ?>">Gagal</span><?php else: ?><span class="pill pill-gray">Belum</span><?php endif; ?></td>
        <td><a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/backup/' . rawurlencode($b['file']))) ?>"><svg class="i i-sm"><use href="#i-down"/></svg>Unduh</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <p class="help" style="padding:12px 22px 18px;margin:0">Verifikasi memeriksa kelengkapan dump (footer), keberadaan semua tabel, dan kesamaan jumlah baris dengan database. Restore ke database uji dilakukan lewat CLI <code>database/tools/restore.php</code> (lihat docs/BACKUP_RESTORE.md); aplikasi sengaja tidak menyediakan restore langsung ke database yang sedang berjalan.</p>
</section>

<section class="card" style="max-width:820px">
  <div class="card-head"><h2>Informasi sistem</h2></div>
  <dl class="kv"><dt>PHP</dt><dd><?= e($sys['php']) ?></dd><dt>Database</dt><dd><?= e($sys['db']) ?></dd><dt>Zona waktu</dt><dd><?= e($sys['tz']) ?></dd><dt>Lingkungan</dt><dd><?= e($sys['env']) ?></dd></dl>
</section>

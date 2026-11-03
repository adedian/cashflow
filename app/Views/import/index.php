<?php /** @var array $batches */
$kindLbl = ['workbook' => 'Workbook Cashflow HME', 'transactions' => 'Transaksi', 'debts' => 'Hutang'];
?>
<section class="card">
  <div class="card-head"><h2>Impor Excel / CSV dengan pemetaan kolom</h2></div>
  <form method="post" action="<?= e(url('/import/upload')) ?>" enctype="multipart/form-data" class="filters" id="wizUpload">
    <?= csrf_field() ?>
    <div class="field grow"><label for="file">Berkas (.xlsx atau .csv, maks. 20 MB)</label><input class="input" type="file" id="file" name="file" accept=".xlsx,.csv" required style="padding-top:9px"></div>
    <div class="field"><label for="kind">Jenis data</label>
      <select class="input" id="kind" name="kind">
        <option value="">Kenali otomatis</option>
        <option value="transactions">Transaksi Cashflow</option>
        <option value="debts">Hutang</option>
      </select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit"><svg class="i i-sm"><use href="#i-up"/></svg>Unggah & analisis</button></div>
  </form>
  <div class="alert alert-info mt-2">
    <b>Alur:</b> unggah → pilih sheet → <b>pemetaan kolom</b> (disarankan otomatis, bisa Anda koreksi) → <b>pratinjau</b> (baris valid, bermasalah, duplikat) → konfirmasi → laporan hasil.
    Tidak ada data yang tersimpan sebelum Anda mengonfirmasi, dan penyimpanan bersifat <b>semua-atau-tidak-sama-sekali</b>. Makro & formula tidak pernah dijalankan; berkas unggahan dihapus setelah selesai atau dibatalkan.
    Format <b>.xls</b> (Excel lama) tidak didukung — simpan ulang sebagai .xlsx. Header “Kas Masuk/Kas Keluar” dikenali sebagai Mutasi Masuk/Mutasi Keluar. Maksimal 20.000 baris data per sheet.
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Impor workbook “CASH FLOW HME” (pemetaan sheet tetap)</h2></div>
  <form method="post" action="<?= e(url('/import/preview')) ?>" enctype="multipart/form-data" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="file2">Berkas Excel (.xlsx, maks. 20 MB)</label><input class="input" type="file" id="file2" name="file" accept=".xlsx" required style="padding-top:9px"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit"><svg class="i i-sm"><use href="#i-up"/></svg>Unggah & periksa</button></div>
  </form>
  <div class="alert alert-info mt-2">
    Khusus workbook asli: <?= e(implode(', ', array_map(static fn($n) => '“' . trim($n) . '”', array_keys(\App\Services\ImportProfile::get()['tx_sheets'])))) ?> → transaksi; “CASH FLOW” (baris 20–97) → proyeksi; “Tagihan PLTS - Fee” (rekap invoice) → invoice.
    Sheet tersembunyi, “Piutang Lampu”, “hutang”, dan “Estimasi biaya” <b>tidak</b> diimpor di jalur ini (untuk sheet “hutang” gunakan impor berpemetaan di atas). Pratinjau tidak mengubah data; impor tidak menimpa data lama.
  </div>
</section>

<section class="card card-flush">
  <div class="card-head"><h2>Riwayat impor</h2></div>
  <?php if (!$batches): ?><div class="empty"><svg class="i"><use href="#i-up"/></svg><p>Belum ada impor.</p></div><?php else: ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Batch</th><th>Berkas</th><th>Jenis</th><th>Waktu</th><th>Oleh</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td data-label="Batch">#<?= (int) $b['id'] ?></td>
        <td data-label="Berkas" class="desc"><?= e($b['filename']) ?><div class="sub">SHA-256 <?= e(substr($b['sha256'], 0, 16)) ?>…</div></td>
        <td data-label="Jenis"><?= e($kindLbl[$b['kind'] ?? 'workbook'] ?? '-') ?></td>
        <td data-label="Waktu" class="nowrap"><?= e(fmt_date($b['created_at'], 'd/m/Y H:i')) ?></td>
        <td data-label="Oleh"><?= e($b['full_name'] ?? '-') ?></td>
        <td data-label="Status"><span class="pill <?= $b['status'] === 'imported' ? 'pill-green' : 'pill-gray' ?>"><?= $b['status'] === 'imported' ? 'Aktif' : 'Dibatalkan' ?></span></td>
        <td class="nowrap">
          <?php if (($b['kind'] ?? 'workbook') !== 'workbook'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/import/' . (int) $b['id'] . '/report')) ?>">Laporan</a><?php endif; ?>
          <?php if ($b['status'] === 'imported'): ?>
          <form method="post" action="<?= e(url('/import/' . (int) $b['id'] . '/void')) ?>" style="display:inline" data-confirm="Batalkan impor batch #<?= (int) $b['id'] ?>? Semua transaksi/hutang hasil impor ini akan di-void (tidak dihitung dalam saldo) dan proyeksi/invoice hasil impor workbook dihapus. Perubahan 'Perbarui data yang cocok' pada data lama tidak dikembalikan. Riwayat tetap tersimpan." data-confirm-ok="Ya, batalkan impor">
            <?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Batalkan batch</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

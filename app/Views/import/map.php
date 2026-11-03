<?php
/** @var string $token @var array $meta @var array $sheets @var string $sheet @var string $kind @var int $hdr @var array $det @var array $letters
 *  @var array $headers @var array $samples @var array $filled @var array $suggest @var array $mapSel @var array $fields @var array $prev
 *  @var int $dataRows @var array $accounts @var int $accountId @var array $errors @var array $posted */
$how = ['template' => ['pill-green', 'Template'], 'exact' => ['pill-green', 'Cocok'], 'likely' => ['pill-yellow', 'Mirip'], 'guess' => ['pill-yellow', 'Dugaan'], 'none' => ['pill-gray', '—']];
$kinds = \App\Services\Import\ImportFields::KINDS;
?>
<?php if ($errors): ?>
<div class="alert alert-danger mb-2" role="alert"><b>Pemetaan belum bisa dilanjutkan:</b>
  <ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>1. Sheet, jenis data & baris header</h2><span class="muted"><?= e($meta['name']) ?> · <?= number_format($dataRows, 0, ',', '.') ?> baris di bawah header</span></div>
  <form class="filters" method="get" action="<?= e(url('/import/map')) ?>" id="mapPick">
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="field"><label for="sheetSel">Sheet</label>
      <select class="input" id="sheetSel" name="sheet">
        <?php foreach ($sheets as $n => $state): ?><option value="<?= e($n) ?>" <?= $n === $sheet ? 'selected' : '' ?>><?= e(trim($n)) ?><?= $state === 'hidden' ? ' (tersembunyi)' : '' ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="kindSel">Jenis data</label>
      <select class="input" id="kindSel" name="kind">
        <?php foreach ($kinds as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $k === $kind ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:0 1 130px"><label for="hdrSel">Baris header</label><input class="input num" type="number" min="1" max="500" id="hdrSel" name="hdr" value="<?= (int) $hdr ?>"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock>Muat ulang kolom</button></div>
  </form>
  <p class="help mt-1 mb-0">Baris header terdeteksi: <b><?= (int) $det['header'] ?></b> (kecocokan nama kolom: transaksi <?= (int) $det['score']['transactions'] ?>, hutang <?= (int) $det['score']['debts'] ?>). Ubah bila salah, lalu “Muat ulang kolom”.
  <?php if ($meta['ext'] === 'csv'): ?> Berkas CSV dibaca sebagai satu sheet.<?php endif; ?></p>
  <?php if ($prev): ?>
  <div class="table-wrap mt-1" style="max-height:230px"><table class="tbl">
    <thead><tr><th>Baris</th><?php foreach ($letters as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($prev as $r => $cells): ?>
      <tr class="<?= $r === $hdr ? 'hdr-row' : '' ?>" <?= $r === $hdr ? 'style="background:var(--blue-50);font-weight:700"' : '' ?>><td class="num"><?= (int) $r ?><?= $r === $hdr ? ' ◄ header' : '' ?></td>
        <?php foreach ($letters as $col): ?><td><?= isset($cells[$col]) ? e(mb_substr(\App\Services\Import\ImportParser::text($cells[$col]['v']), 0, 28)) : '' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>

<form method="post" action="<?= e(url('/import/review')) ?>" id="mapForm">
  <?= csrf_field() ?>
  <input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="sheet" value="<?= e($sheet) ?>">
  <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="hdr" value="<?= (int) $hdr ?>">

  <section class="card card-flush">
    <div class="card-head"><h2>2. Pemetaan kolom → field sistem (<?= e($kinds[$kind]) ?>)</h2></div>
    <div class="table-wrap"><table class="tbl stack">
      <thead><tr><th>Kolom</th><th>Header di Excel</th><th>Field tujuan</th><th>Saran</th><th class="r">Terisi</th><th>Contoh isi</th></tr></thead>
      <tbody>
      <?php foreach ($letters as $col):
          $sg = $suggest[$col]; $sel = $mapSel[$col] ?? ''; $n = (int) ($filled[$col] ?? 0);
          $unk = $sel === '' && $n > 0;
      ?>
        <tr class="<?= $unk ? 'map-unknown' : '' ?>">
          <td data-label="Kolom"><b><?= e($col) ?></b></td>
          <td data-label="Header" class="desc"><?= $headers[$col] !== '' ? e($headers[$col]) : '<span class="muted">(tanpa header)</span>' ?></td>
          <td data-label="Field" class="map-field">
            <select class="input" name="map[<?= e($col) ?>]" aria-label="Field untuk kolom <?= e($col) ?>">
              <option value="">— Abaikan kolom ini —</option>
              <?php foreach ($fields as $fk => $fd): ?><option value="<?= e($fk) ?>" <?= $sel === $fk ? 'selected' : '' ?>><?= e($fd['label']) ?><?= $fd['req'] ? ' *' : '' ?></option><?php endforeach; ?>
            </select>
            <?php if ($unk): ?><div class="err">Berisi data tetapi belum dipetakan.</div><?php endif; ?>
          </td>
          <td data-label="Saran"><span class="pill <?= $how[$sg['how']][0] ?>"><?= $how[$sg['how']][1] ?></span><?php if ($sg['note']): ?><div class="sub"><?= e($sg['note']) ?></div><?php endif; ?></td>
          <td data-label="Terisi" class="r num"><?= number_format($n, 0, ',', '.') ?></td>
          <td data-label="Contoh" class="desc samp"><span class="sub"><?= e(implode(' · ', $samples[$col] ?? [])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p class="help" style="padding:0 22px 14px;margin:0">* wajib. Satu field hanya boleh dipetakan ke satu kolom. “Mutasi Masuk/Keluar” juga dikenali dari header lama “Kas Masuk/Kas Keluar”, “Debit/Kredit”.
      Pada rekening pinjaman, Debit = pembayaran dan Kredit = penarikan.</p>
  </section>

  <section class="card">
    <div class="card-head"><h2>3. Tujuan & opsi</h2></div>
    <div class="form-grid">
      <?php if ($kind === 'transactions'): ?>
      <div class="field"><label for="account_id">Rekening tujuan <span class="req">*</span></label>
        <select class="input" id="account_id" name="account_id" required>
          <option value="">— pilih rekening —</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (saldo awal <?= e(fmt_date($a['opening_date'])) ?>)</option><?php endforeach; ?>
        </select>
        <span class="help">Semua baris masuk ke rekening ini. Baris bertanggal sebelum saldo awal rekening ditolak.</span></div>
      <?php else: ?>
      <div class="field"><label for="debt_category">Jenis hutang (baris tanpa kolom jenis)</label>
        <select class="input" id="debt_category" name="debt_category">
          <?php foreach (\App\Services\DebtService::CATEGORIES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($posted['debt_category'] ?? 'usaha') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
        <span class="help">Dipakai untuk semua baris yang tidak punya kolom “Jenis Hutang” (atau isinya tidak dikenali). Hutang muncul di tab jenis ini.</span></div>
      <div class="field"><span class="help">Data Hutang masuk ke menu <b>Hutang</b>, <b>bukan</b> ke transaksi/mutasi keluar, sehingga saldo dan laporan tidak berubah.</span></div>
      <?php endif; ?>
      <div class="field"><label class="check"><input type="checkbox" name="ack_ignored" value="1" <?= !empty($posted['ack_ignored']) ? 'checked' : '' ?>>Saya mengerti kolom berisi data yang diabaikan tidak akan diimpor</label>
        <span class="help">Wajib dicentang bila ada kolom berisi data yang tidak dipetakan.</span></div>
      <div class="field"><label class="check"><input type="checkbox" name="save_template" value="1" id="saveTpl" <?= !empty($posted['save_template']) ? 'checked' : '' ?>>Simpan pemetaan ini sebagai template</label>
        <input class="input" name="template_name" maxlength="80" placeholder="Nama template (mis. Mutasi BCA bulanan)" value="<?= e($posted['template_name'] ?? '') ?>"></div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit">Lanjut ke pratinjau</button>
      <button class="btn btn-ghost" type="submit" formaction="<?= e(url('/import/abort')) ?>" formnovalidate data-no-lock>Batalkan & hapus berkas</button>
    </div>
  </section>
</form>

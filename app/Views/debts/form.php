<?php /** @var array $t @var array $errors @var ?int $id @var array $suppliers @var array $accounts */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$acctLabel = static function (array $a): string {
    $bits = array_filter([$a['name'], $a['bank_name'] ?? null, !empty($a['account_no']) ? 'No. ' . $a['account_no'] : null]);
    return implode(' · ', $bits);
};
$curSup = (int) $v('supplier_id', 0);
$curAcc = (int) $v('source_account_id', 0);
$curCat = (string) $v('category', 'usaha');
?>
<section class="card" style="max-width:820px">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <form method="post" action="<?= e($id ? url('/debts/' . $id) : url('/debts')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field full">
        <label for="category">Jenis hutang <span class="req">*</span></label>
        <select class="input" id="category" name="category" required>
          <?php foreach (\App\Services\DebtService::CATEGORIES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $curCat === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select><?= $fe('category') ?>
      </div>
      <div class="field full">
        <label for="supplier_id">Nama supplier / vendor / pihak pemberi hutang <span class="req">*</span></label>
        <select class="input" id="supplier_id" name="supplier_id" data-combo data-combo-placeholder="Ketik untuk mencari supplier…" required>
          <option value="">— pilih supplier —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $curSup === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' (nonaktif)' ?></option><?php endforeach; ?>
        </select><?= $fe('supplier') ?>
        <span class="help"><?= $suppliers ? '' : 'Belum ada supplier. ' ?>Supplier dikelola di <a href="<?= e(url('/master?tab=suppliers')) ?>">Master Data → Supplier</a>; tambahkan di sana bila belum tersedia.</span>
      </div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="500" value="<?= e($v('description')) ?>"><?= $fe('description') ?></div>
      <div class="field"><label for="amount">Nominal hutang (Rp) <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>" required><?= $fe('amount') ?></div>
      <div class="field"><label for="due_date">Tanggal jatuh tempo</label><input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>"><?= $fe('due_date') ?></div>
      <div class="field"><label for="debt_date">Tanggal transaksi</label><input class="input" type="date" id="debt_date" name="debt_date" value="<?= e($v('debt_date')) ?>"><?= $fe('debt_date') ?></div>
      <div class="field"><label for="ref_no">Nomor transaksi / dokumen</label><input class="input" id="ref_no" name="ref_no" maxlength="80" value="<?= e($v('ref_no')) ?>"><?= $fe('ref_no') ?></div>
      <div class="field full">
        <label for="source_account_id">Rekening sumber pembayaran <span class="help">(rencana, opsional)</span></label>
        <select class="input" id="source_account_id" name="source_account_id" data-combo data-combo-placeholder="Ketik untuk mencari rekening…">
          <option value="">— belum ditentukan —</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $curAcc === (int) $a['id'] ? 'selected' : '' ?>><?= e($acctLabel($a)) ?></option><?php endforeach; ?>
        </select><?= $fe('source_account_id') ?>
        <?php if (!$curAcc && trim((string) $v('source_account')) !== ''): ?><span class="help">Nilai lama dari Excel: <b><?= e($v('source_account')) ?></b> — pilih rekening di atas untuk memperbaruinya.</span><?php endif; ?>
        <span class="help">Hanya informasi rencana; <b>tidak mengurangi saldo</b>. Rekening yang benar-benar dipakai dipilih di form Transaksi saat hutang dibayar.</span>
      </div>
      <div class="field full"><label for="note">Catatan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <p class="help mt-1">Hutang tidak memengaruhi saldo, laporan, maupun proyeksi sampai pembayarannya dicatat di menu Transaksi (Mutasi Keluar). Tanpa tanggal jatuh tempo, hutang tidak muncul di Pengingat Pembayaran. No. urut dibuat otomatis.</p>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Simpan</button><a class="btn btn-ghost" href="<?= e(url($id ? '/debts/' . $id : '/debts?category=' . $curCat)) ?>">Batal</a></div>
  </form>
</section>

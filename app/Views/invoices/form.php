<?php /** @var array $t @var array $errors @var ?int $id @var string $kind */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$lampu = $kind === 'lampu';
?>
<section class="card" style="max-width:820px">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <form method="post" action="<?= e($id ? url('/invoices/' . $id) : url('/invoices')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="form-grid">
      <div class="field full"><label for="customer">Customer <span class="req">*</span></label><input class="input" id="customer" name="customer" maxlength="160" value="<?= e($v('customer')) ?>" required><?= $fe('customer') ?></div>
      <?php if (!$lampu): ?><div class="field"><label for="project_name">Proyek</label><input class="input" id="project_name" name="project_name" maxlength="200" value="<?= e($v('project_name')) ?>"></div><?php endif; ?>
      <div class="field"><label for="invoice_no"><?= $lampu ? 'No. surat jalan' : 'No. invoice' ?></label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>"></div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="255" value="<?= e($v('description')) ?>"></div>
      <div class="field"><label for="invoice_date">Tanggal <?= $lampu ? 'nota' : 'invoice' ?></label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>"><?= $fe('invoice_date') ?></div>
      <?php if ($lampu): ?>
      <div class="field"><label for="due_date">Jatuh tempo</label><input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>"><?= $fe('due_date') ?></div>
      <div class="field"><label for="due_note">Keterangan jatuh tempo (mis. CASH)</label><input class="input" id="due_note" name="due_note" maxlength="30" value="<?= e($v('due_note')) ?>"></div>
      <div class="field"><label for="salesperson">Sales</label><input class="input" id="salesperson" name="salesperson" maxlength="40" value="<?= e($v('salesperson')) ?>"></div>
      <?php endif; ?>
      <div class="field"><label for="amount">Nominal tagihan (Rp) <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>" required><?= $fe('amount') ?></div>
      <div class="field"><label for="paid">Sudah dibayar (Rp) <span class="help">— total, termasuk penerimaan lewat Transaksi</span></label><input class="input num" id="paid" name="paid" inputmode="decimal" value="<?= e($v('paid')) ?>"><?= $fe('paid') ?></div>
      <?php if ($lampu): ?>
      <div class="field"><label for="paid_date">Tanggal bayar</label><input class="input" type="date" id="paid_date" name="paid_date" value="<?= e($v('paid_date')) ?>"><?= $fe('paid_date') ?></div>
      <div class="field"><label for="pay_ref">Kode bukti pembayaran (mis. BM 11/02)</label><input class="input" id="pay_ref" name="pay_ref" maxlength="40" value="<?= e($v('pay_ref')) ?>"></div>
      <?php endif; ?>
      <div class="field full"><label class="check"><input type="checkbox" name="paid_known" value="1" <?= !empty($t['paid_known']) ? 'checked' : '' ?>>Pembayaran sudah dipastikan</label>
        <span class="help">Hilangkan centang bila nominal pembayaran belum pasti (status “Perlu konfirmasi”: tidak dihitung lunas maupun belum dibayar).</span></div>
      <div class="field full"><label for="note">Catatan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Simpan</button><a class="btn btn-ghost" href="<?= e(url($id ? '/invoices/' . $id : '/invoices?kind=' . $kind)) ?>">Batal</a></div>
  </form>
</section>
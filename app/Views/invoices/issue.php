<?php /** Formulir "Terbitkan Invoice". @var array $t @var array $errors */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$id = (int) $t['id'];
?>
<section class="card form-wide" style="max-width:760px">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <div class="alert alert-info mb-2">Menerbitkan invoice mengubah status data yang sama (ID, nominal, dan riwayatnya tetap). Setelah diterbitkan, invoice masuk daftar Piutang Proyek dan Proyeksi (penerimaan) pada tanggal jatuh tempo, serta tidak lagi dihitung sebagai Invoice Belum Terbit.</div>
  <dl class="kv mb-2">
    <dt>Customer</dt><dd><b><?= e($t['customer']) ?></b></dd>
    <?php if ($t['project_name']): ?><dt>Proyek</dt><dd><?= e($t['project_name']) ?></dd><?php endif; ?>
    <dt>Nominal tagihan</dt><dd class="num"><b><?= e(money($t['amount'])) ?></b> <span class="muted">· ubah lewat “Ubah” bila perlu</span></dd>
  </dl>
  <form method="post" action="<?= e(url('/invoices/' . $id . '/issue')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="invoice_no">No. invoice <span class="req">*</span></label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>" required><?= $fe('invoice_no') ?></div>
      <div class="field"><label for="invoice_date">Tanggal invoice <span class="req">*</span></label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>" required><?= $fe('invoice_date') ?></div>
      <div class="field"><label for="due_date">Tgl. jatuh tempo <span class="req">*</span></label><input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>" required><?= $fe('due_date') ?>
        <span class="help">Invoice yang belum lunas otomatis muncul di Proyeksi (penerimaan) pada tanggal ini.</span></div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="255" value="<?= e($v('description')) ?>"></div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Terbitkan Invoice</button><a class="btn btn-ghost" href="<?= e(url('/invoices/' . $id)) ?>">Batal</a></div>
  </form>
</section>

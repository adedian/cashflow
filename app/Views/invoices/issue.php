<?php /** Formulir "Terbitkan Invoice" (bertahap per project). @var array $t @var array $errors @var array $src @var array $balances @var string $srcIssued @var string $srcProject */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$id = (int) $src['id'];
$srcRem = bcsub((string) $src['amount'], $srcIssued, 2);
$pid = (int) $v('project_id', 0);
$num = static fn($x): string => $x === '' || $x === null ? '' : (preg_match('/^\d+(\.\d+)?$/', (string) $x) ? money((string) $x, false) : (string) $x);
$curBal = $pid > 0 ? ($balances[$pid]['remaining'] ?? '0.00') : $srcRem;
?>
<section class="card form-wide" style="max-width:820px">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <div class="alert alert-info mb-2">Penerbitan bisa <b>bertahap</b>: tiap penerbitan membuat satu invoice baru (masuk daftar Piutang dan Proyeksi sesuai jatuh tempo) dan <b>mengurangi saldo</b> Invoice Belum Terbit project ini. Sisa dihitung: nominal awal − yang sudah diterbitkan.</div>
  <dl class="kv mb-2">
    <dt>Customer</dt><dd><b><?= e($src['customer']) ?></b></dd>
    <dt>Invoice Belum Terbit</dt><dd>#<?= $id ?><?= $src['invoice_no'] ? ' · ' . e($src['invoice_no']) : '' ?><?= $src['description'] ? ' · ' . e($src['description']) : '' ?></dd>
    <dt>Nominal awal</dt><dd class="num"><?= e(money($src['amount'])) ?></dd>
    <dt>Sudah diterbitkan</dt><dd class="num"><?= e(money($srcIssued)) ?></dd>
    <dt>Sisa pencatatan ini</dt><dd class="num"><b><?= e(money($srcRem)) ?></b></dd>
  </dl>
  <form method="post" action="<?= e(url('/invoices/' . $id . '/issue')) ?>" novalidate id="issueForm">
    <?= csrf_field() ?>
    <input type="hidden" name="issue_key" value="<?= e((string) $v('issue_key')) ?>">
    <div class="form-grid">
      <div class="field"><label for="issue_project">Project <span class="req">*</span></label>
        <?php if ((int) ($src['project_id'] ?? 0) > 0 || $balances): ?>
        <select class="input" id="issue_project" name="project_id" required>
          <?php if ($pid > 0 && !isset($balances[$pid])): ?><option value="<?= $pid ?>" data-remaining="0" selected><?= e($srcProject) ?> (tanpa sisa)</option><?php endif; ?>
          <?php foreach ($balances as $b): ?><option value="<?= (int) $b['id'] ?>" data-remaining="<?= e($b['remaining']) ?>" <?= $pid === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?> — sisa <?= e(money($b['remaining'])) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="hidden" name="project_id" value="0"><input class="input" value="<?= e($srcProject !== '' ? $srcProject : '(tanpa project)') ?>" readonly tabindex="-1">
        <?php endif; ?>
        <?= $fe('project_id') ?><span class="help">Saldo tersedia project: <b class="num" id="saldoView"><?= e(money($curBal)) ?></b>. Bila ada beberapa pencatatan pada project yang sama, saldo dipotong dari pencatatan ini lebih dulu, lalu yang tertua.</span></div>
      <div class="field"><label for="amount">Nominal yang diterbitkan (Rp) <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($errors ? (string) $v('amount') : $num($v('amount'))) ?>" required autocomplete="off"><?= $fe('amount') ?>
        <span class="help">Tidak boleh melebihi saldo project. Boleh sebagian; sisanya tetap Belum Terbit.</span></div>
      <div class="field"><label for="invoice_no">No. invoice <span class="req">*</span></label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>" required><?= $fe('invoice_no') ?></div>
      <div class="field"><label for="invoice_date">Tanggal invoice <span class="req">*</span></label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>" required><?= $fe('invoice_date') ?></div>
      <div class="field"><label for="due_date">Tgl. jatuh tempo <span class="req">*</span></label><input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>" required><?= $fe('due_date') ?>
        <span class="help">Invoice yang belum lunas otomatis muncul di Proyeksi (penerimaan) pada tanggal ini.</span></div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="255" value="<?= e($v('description')) ?>"></div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Terbitkan Invoice</button><a class="btn btn-ghost" href="<?= e(url('/invoices/' . $id)) ?>">Batal</a></div>
  </form>
</section>

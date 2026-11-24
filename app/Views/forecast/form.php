<?php
/** @var array $t @var array $errors @var ?int $id @var bool $locked */
$dis = !empty($locked) ? ' disabled' : '';
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
?>
<section class="card form-wide">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <?php if (!empty($locked)): ?><div class="alert alert-info mb-2">Item ini tertaut ke Hutang/Piutang: <b>nominal, tanggal, bulan, dan jenis</b> diatur otomatis dari sana (nominal = sisa, tanggal = jatuh tempo) dan tidak dapat diubah di sini. Uraian, rekening, dan status aktif tetap dapat diubah.</div><?php endif; ?>
  <form method="post" action="<?= e($id ? url('/forecast/' . $id) : url('/forecast')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="period_month">Bulan proyeksi <span class="req">*</span></label><input class="input" type="month" id="period_month" name="period_month" value="<?= e($v('period_month')) ?>" required<?= $dis ?>><?= $fe('period_month') ?></div>
      <div class="field"><label for="expected_date">Perkiraan tanggal</label><input class="input" type="date" id="expected_date" name="expected_date" value="<?= e($v('expected_date')) ?>"<?= $dis ?>><?= $fe('expected_date') ?></div>
      <div class="field"><label>Jenis <span class="req">*</span></label>
        <div style="display:flex;gap:18px;height:44px;align-items:center">
          <label class="check"><input type="radio" name="direction" value="in" <?= $v('direction') === 'in' ? 'checked' : '' ?><?= $dis ?>>Penerimaan</label>
          <label class="check"><input type="radio" name="direction" value="out" <?= $v('direction') === 'out' ? 'checked' : '' ?><?= $dis ?>>Pengeluaran</label>
        </div><?= $fe('direction') ?></div>
      <div class="field"><label for="amount">Nominal (Rp) <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>" required<?= $dis ?>><?= $fe('amount') ?></div>
      <div class="field full"><label for="description">Uraian <span class="req">*</span></label><input class="input" id="description" name="description" maxlength="500" value="<?= e($v('description')) ?>" required><?= $fe('description') ?></div>
      <?php
      $curHint = trim((string) $v('account_hint'));
      $names = array_map(static fn($a) => (string) $a['name'], $accounts ?? []);
      ?>
      <div class="field"><label for="account_hint">Rekening / sumber</label>
        <select class="input" id="account_hint" name="account_hint" data-combo data-combo-placeholder="Ketik untuk mencari rekening…">
          <option value="">— belum ditentukan —</option>
          <?php foreach ($accounts ?? [] as $a): $bits = array_filter([$a['name'], $a['bank_name'] ?? null, !empty($a['account_no']) ? 'No. ' . $a['account_no'] : null]); ?>
            <option value="<?= e($a['name']) ?>" <?= $curHint === (string) $a['name'] ? 'selected' : '' ?>><?= e(implode(' · ', $bits)) ?></option>
          <?php endforeach; ?>
          <?php if ($curHint !== '' && !in_array($curHint, $names, true)): ?><option value="<?= e($curHint) ?>" selected><?= e($curHint) ?> (nilai lama)</option><?php endif; ?>
        </select></div>
      <div class="field" style="justify-content:flex-end"><label class="check"><input type="checkbox" name="is_active" value="1" <?= !empty($t['is_active']) ? 'checked' : '' ?>>Aktif (dihitung dalam proyeksi)</label></div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Simpan</button><a class="btn btn-ghost" href="<?= e(url('/forecast')) ?>">Batal</a></div>
  </form>
</section>
